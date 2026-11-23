<?php

namespace Database\Factories;

use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Models\AssistantDisclosure;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssistantDisclosure> */
class AssistantDisclosureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_key' => hash('sha256', fake()->unique()->uuid()),
            'surface' => 'calculate_formula',
            'categories' => [AssistantDataCategory::FormulaCost->value],
            'outcome' => AssistantDisclosureOutcome::Sent,
            'user_id' => null,
            'occurred_at' => now(),
        ];
    }
}
