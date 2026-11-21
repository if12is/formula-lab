<?php

namespace Database\Factories;

use App\Enums\RuleSetStatus;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RuleSet> */
class RuleSetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'code' => 'rule-set-'.fake()->unique()->bothify('??##'),
            'version' => '1.0.0',
            'name' => 'Rule set '.fake()->unique()->bothify('??##'),
            'scope_notes' => null,
            'status' => RuleSetStatus::Draft,
            'hash' => null,
            'source_id' => null,
            'published_at' => null,
        ];
    }
}
