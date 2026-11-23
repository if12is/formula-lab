<?php

namespace App\Services\Assistant;

use App\Actions\Assistant\RecordAssistantRequestAction;
use App\Actions\Assistant\RunAssistantTurnAction;
use App\Enums\Assistant\AssistantRequestStatus;
use App\Exceptions\Domain\AssistantException;
use App\Models\AssistantRequest;
use App\Support\Assistant\TurnUsage;
use App\Support\DTOs\AssistantReply;
use Illuminate\Support\Facades\Log;

/**
 * The assistant's single entry: asks the model (through RunAssistantTurnAction), and records the usage of every question, also one
 * that failed. The log line holds ids, status and token counts only: never the question, the reply or a key.
 */
final class AssistantService
{
    public function __construct(
        private readonly RunAssistantTurnAction $runTurn,
        private readonly RecordAssistantRequestAction $recordRequest,
    ) {}

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  string|null  $consentKey  The browser session whose privacy consent gates the tools (P-10).
     * @return array{reply: AssistantReply, requestId: string}
     *
     * @throws AssistantException When the provider fails (the failure is recorded first).
     */
    public function ask(string $question, array $history = [], ?int $userId = null, ?string $tenantId = null, ?string $consentKey = null): array
    {
        $usage = new TurnUsage;
        $started = hrtime(true);

        try {
            $reply = ($this->runTurn)($question, $history, $usage, $consentKey);
        } catch (AssistantException $exception) {
            $this->record(AssistantRequestStatus::Failed, $usage, $started, $userId, $tenantId, $exception->reason);

            throw $exception;
        }

        $record = $this->record($reply->status, $usage, $started, $userId, $tenantId);

        return ['reply' => $reply, 'requestId' => (string) $record->getKey()];
    }

    private function record(AssistantRequestStatus $status, TurnUsage $usage, int $started, ?int $userId, ?string $tenantId, ?string $errorCode = null): AssistantRequest
    {
        $record = ($this->recordRequest)($status, $usage, intdiv(hrtime(true) - $started, 1_000_000), $userId, $tenantId, $errorCode);

        Log::info('assistant.request', [
            'request_id' => $record->getKey(),
            'status' => $status->value,
            'error' => $errorCode,
            'model' => $record->model,
            'api_calls' => $usage->apiCalls,
            'tool_calls' => $usage->toolCalls,
            'guard_retries' => $usage->guardRetries,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
        ]);

        return $record;
    }
}
