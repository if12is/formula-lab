<?php

namespace Database\Factories;

use App\Enums\LabTrialStatus;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LabTrial> */
class LabTrialFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'tenant_id' => fn (array $attributes): string => FormulaVersion::query()->with('formula')->findOrFail($attributes['formula_version_id'])->formula->tenant_id,
            'code' => 'T-'.fake()->unique()->numerify('####'),
            'status' => LabTrialStatus::InProgress,
            'batch_size' => '500',
            'batch_unit' => 'g',
            'trial_date' => '2026-11-20',
            'operator' => 'Operator A',
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => LabTrialStatus::Completed, 'completed_at' => '2026-11-21 10:00:00']);
    }
}
