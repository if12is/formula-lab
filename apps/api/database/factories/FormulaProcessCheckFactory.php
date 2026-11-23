<?php

namespace Database\Factories;

use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaProcessCheck> */
class FormulaProcessCheckFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_process_step_id' => FormulaProcessStep::factory(),
            'check_no' => 1,
            'property' => 'ph',
            'unit' => 'pH',
            'limit_min' => null,
            'limit_max' => null,
            'source_id' => null,
            'confidence' => null,
        ];
    }
}
