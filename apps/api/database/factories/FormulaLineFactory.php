<?php

namespace Database\Factories;

use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaLine> */
class FormulaLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory(),
            'line_no' => 1,
            'trade_material_id' => TradeMaterial::factory(),
            'percent_ww' => '10',
            'is_qs' => false,
            'phase' => null,
        ];
    }
}
