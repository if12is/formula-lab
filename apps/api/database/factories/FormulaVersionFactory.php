<?php

namespace Database\Factories;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaVersion> */
class FormulaVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_id' => Formula::factory(),
            'version' => 1,
            'hash' => hash('sha256', fake()->unique()->uuid()),
            'status' => FormulaStatus::Draft,
            'reason' => null,
            'parent_version_id' => null,
            'created_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => FormulaStatus::Approved]);
    }
}
