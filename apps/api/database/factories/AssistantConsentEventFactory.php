<?php

namespace Database\Factories;

use App\Enums\Assistant\AssistantConsentEventType;
use App\Enums\Assistant\AssistantDataCategory;
use App\Models\AssistantConsentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantConsentEvent> */
class AssistantConsentEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_key' => hash('sha256', fake()->unique()->uuid()),
            'event' => AssistantConsentEventType::Granted,
            'categories' => [AssistantDataCategory::FormulaComposition->value],
            'end_reason' => null,
            'user_id' => null,
            'occurred_at' => now(),
        ];
    }
}
