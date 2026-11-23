<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Exceptions\Domain\AssistantException;
use App\Integrations\Anthropic\MessageResponse;
use App\Integrations\Anthropic\MessagesClient;
use App\Support\Assistant\AssistantPrompt;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Assistant\NumberGuard;
use App\Support\Assistant\ToolContext;
use App\Support\Assistant\TurnUsage;
use App\Support\DTOs\AssistantReply;

/**
 * One question to the assistant: the model <-> tool conversation, then the number guard on the final text (P-04). A reply with a
 * number that is neither the user's nor a tool's of this turn is sent back to the model (at most `guard.max_retries` times) and, if
 * it never comes clean, replaced by the safe fallback message, which has no number in it.
 */
final class RunAssistantTurnAction
{
    public function __construct(
        private readonly MessagesClient $client,
        private readonly AssistantToolRegistry $tools,
        private readonly NumberGuard $guard,
        private readonly ExecuteAssistantToolAction $executeTool,
    ) {}

    /**
     * @param  list<array{role: string, text: string}>  $history  Earlier messages of the conversation, oldest first.
     *
     * @throws AssistantException When the provider fails or the model keeps calling tools past the limit.
     */
    public function __invoke(string $question, array $history, TurnUsage $usage): AssistantReply
    {
        $messages = array_map(fn (array $message): array => ['role' => $message['role'], 'content' => $message['text']], $history);
        $messages[] = ['role' => 'user', 'content' => $question];

        $userTexts = [$question, ...array_column(array_filter($history, fn (array $message): bool => $message['role'] === 'user'), 'text')];
        $identifierKeys = (array) config('assistant.guard.identifier_keys');
        $userNumbers = $this->guard->collect($userTexts);
        $context = new ToolContext;
        $toolsUsed = [];
        $toolRounds = 0;
        $maxRounds = (int) config('assistant.max_tool_rounds');
        $maxRetries = (int) config('assistant.guard.max_retries');

        while (true) {
            $response = $this->client->create($this->payload($messages));
            $usage->apiCalls++;
            $usage->inputTokens += $response->inputTokens;
            $usage->outputTokens += $response->outputTokens;

            if ($response->toolUses() !== []) {
                if (++$toolRounds > $maxRounds) {
                    throw AssistantException::toolRoundsExceeded($maxRounds);
                }
                $messages[] = $response->assistantMessage();
                $messages[] = ['role' => 'user', 'content' => $this->runTools($response, $userNumbers, $context, $usage, $toolsUsed)];

                continue;
            }

            $reply = $response->text();
            $violations = $reply === '' ? [] : $this->guard->violations($reply, $userNumbers + $this->guard->collect($context->results(), $identifierKeys));
            if ($reply !== '' && $violations === []) {
                return new AssistantReply($reply, AssistantRequestStatus::Answered, $toolsUsed);
            }

            if ($reply === '' || $usage->guardRetries >= $maxRetries) {
                return new AssistantReply(__('assistant.fallback_message', [], AssistantPrompt::LOCALE), AssistantRequestStatus::Fallback, $toolsUsed);
            }

            $usage->guardRetries++;
            $messages[] = $response->assistantMessage();
            $messages[] = ['role' => 'user', 'content' => __('assistant.guard_retry', ['numbers' => implode(', ', $violations)], AssistantPrompt::LOCALE)];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function payload(array $messages): array
    {
        return [
            'model' => (string) config('assistant.model'),
            'max_tokens' => (int) config('assistant.max_output_tokens'),
            'temperature' => config('assistant.temperature'),
            'system' => AssistantPrompt::system(),
            'tools' => $this->tools->definitions(),
            'messages' => $messages,
        ];
    }

    /**
     * @param  array<string, true>  $userNumbers
     * @param  list<string>  $toolsUsed
     * @return list<array<string, mixed>>
     */
    private function runTools(MessageResponse $response, array $userNumbers, ToolContext $context, TurnUsage $usage, array &$toolsUsed): array
    {
        $blocks = [];
        foreach ($response->toolUses() as $use) {
            $usage->toolCalls++;
            $toolsUsed[] = $use['name'];
            $outcome = ($this->executeTool)($use['id'], $use['name'], $use['input'], $userNumbers, $context);
            $blocks[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $outcome['content'], ...($outcome['is_error'] ? ['is_error' => true] : [])];
        }

        return $blocks;
    }
}
