<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Models\AssistantRequest;
use App\Support\Assistant\AssistantCostCalculator;
use App\Support\Assistant\TurnUsage;

/** Writes the usage record of one question: tokens in and out, the calls it took, and the cost when the prices are known (else null). */
final class RecordAssistantRequestAction
{
    public function __construct(private readonly AssistantCostCalculator $costs) {}

    public function __invoke(AssistantRequestStatus $status, TurnUsage $usage, int $durationMs, ?int $userId, ?string $tenantId, ?string $errorCode = null): AssistantRequest
    {
        $cost = $this->costs->cost($usage->inputTokens, $usage->outputTokens);

        return AssistantRequest::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'model' => (string) config('assistant.model'),
            'status' => $status,
            'api_calls' => $usage->apiCalls,
            'tool_calls' => $usage->toolCalls,
            'guard_retries' => $usage->guardRetries,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost' => $cost['amount'] ?? null,
            'cost_currency' => $cost['currency'] ?? null,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
        ]);
    }
}
