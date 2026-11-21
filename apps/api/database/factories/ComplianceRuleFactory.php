<?php

namespace Database\Factories;

use App\Models\ComplianceRule;
use App\Models\RuleSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceRule> */
class ComplianceRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'rule_set_id' => RuleSet::factory(),
            'rule' => [
                'id' => 'rule-'.fake()->unique()->bothify('??##'),
                'version' => '1.0.0',
                'scope' => ['productTypes' => ['product_type_a']],
                'target' => ['substanceCas' => 'TEST-0001'],
                'operator' => '<=',
                'limit' => '0.5',
                'unit' => '%w/w',
                'source' => null,
                'pendingSource' => true,
                'messageKey' => 'compliance.rule.custom',
            ],
            'message' => null,
            'active' => true,
            'position' => fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
