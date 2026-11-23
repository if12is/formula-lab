<?php

namespace Database\Factories;

use App\Enums\Assistant\AssistantRequestStatus;
use App\Models\AssistantRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantRequest> */
class AssistantRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'user_id' => null,
            'model' => 'test-model',
            'status' => AssistantRequestStatus::Answered,
            'api_calls' => 1,
            'tool_calls' => 0,
            'guard_retries' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost' => null,
            'cost_currency' => null,
            'error_code' => null,
            'duration_ms' => 0,
        ];
    }
}
