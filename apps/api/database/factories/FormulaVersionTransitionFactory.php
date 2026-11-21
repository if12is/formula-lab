<?php

namespace Database\Factories;

use App\Enums\FormulaStatus;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaVersionTransition> */
class FormulaVersionTransitionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'from_status' => FormulaStatus::Draft,
            'to_status' => FormulaStatus::LabTesting,
            'signed_by' => null,
            'comment' => null,
            'version_hash' => hash('sha256', fake()->unique()->uuid()),
            'basis' => null,
        ];
    }
}
