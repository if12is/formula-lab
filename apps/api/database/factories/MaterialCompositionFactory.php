<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\MaterialComposition;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialComposition> */
class MaterialCompositionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'trade_material_id' => TradeMaterial::factory(),
            'cas' => fake()->unique()->numerify('#####-##-#'),
            'inci' => null,
            'pct_ww' => '10',
            'source_id' => Source::factory(),
            'confidence' => Confidence::L2SupplierTds,
            'valid_from' => '2026-03-01',
            'created_by' => null,
        ];
    }
}
