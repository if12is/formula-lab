<?php

namespace Tests\fakes\anthropic;

use App\Exceptions\Domain\AssistantException;
use App\Integrations\Anthropic\MessageResponse;
use App\Integrations\Anthropic\MessagesClient;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * A scripted stand-in for the model provider: no test reaches the network. Queue the answers the "model" gives in order, and read back
 * the payloads it was sent.
 *
 *     $fake = FakeMessagesClient::bind($this->app);
 *     $fake->replyText('The cost is 16');
 *     $fake->callTool('calculate_formula', ['formulaVersionId' => $id]);
 */
final class FakeMessagesClient implements MessagesClient
{
    /** @var list<MessageResponse|AssistantException> */
    private array $queue = [];

    /** @var list<array<string, mixed>> The payload of every call, in order. */
    public array $payloads = [];

    private int $toolUseCounter = 0;

    public static function bind(Application $app): self
    {
        $fake = new self;
        $app->instance(MessagesClient::class, $fake);

        return $fake;
    }

    public function replyText(string $text, int $inputTokens = 100, int $outputTokens = 20): self
    {
        $this->queue[] = new MessageResponse([['type' => 'text', 'text' => $text]], 'end_turn', $inputTokens, $outputTokens);

        return $this;
    }

    /** @param  array<string, mixed>  $input */
    public function callTool(string $name, array $input, int $inputTokens = 100, int $outputTokens = 20): self
    {
        $id = 'toolu_'.++$this->toolUseCounter;
        $this->queue[] = new MessageResponse([['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'tool_use', $inputTokens, $outputTokens);

        return $this;
    }

    public function fail(AssistantException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function create(array $payload): MessageResponse
    {
        $this->payloads[] = $payload;
        $next = array_shift($this->queue) ?? throw new LogicException('The fake provider has no more scripted answers.');

        if ($next instanceof AssistantException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->payloads);
    }

    /** @return list<array<string, mixed>> The tool_result blocks the model was sent after its latest tool call. */
    public function lastToolResults(): array
    {
        $last = end($this->payloads);
        $message = $last['messages'][array_key_last($last['messages'])] ?? [];

        return is_array($message['content'] ?? null) ? $message['content'] : [];
    }
}
