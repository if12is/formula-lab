<?php

namespace Database\Factories;

use App\Enums\Production\ProcessStepKind;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaProcessStep> */
class FormulaProcessStepFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'step_no' => 1,
            'kind' => ProcessStepKind::Mix,
            'phase' => null,
            'formula_line_id' => null,
            'temperature_c' => null,
            'mixing_speed_rpm' => null,
            'duration_minutes' => null,
            'note' => null,
        ];
    }
}
