<?php

namespace Database\Factories;

use App\Enums\FormulaVersionOrigin;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionProvenance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaVersionProvenance> */
class FormulaVersionProvenanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'origin' => FormulaVersionOrigin::Optimizer,
            'tier' => 'economy',
            'optimizer_inputs' => [],
            'optimizer_version' => '0.0.0-test',
            'solver' => 'test-solver 0',
            'input_hash' => hash('sha256', fake()->unique()->uuid()),
            'output_hash' => hash('sha256', fake()->unique()->uuid()),
            'cost_per_kg' => '1',
        ];
    }
}
