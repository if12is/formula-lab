<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\Price;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Price> */
class PriceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'trade_material_id' => TradeMaterial::factory(),
            'sanad_supplier_uuid' => null,
            'amount' => '10.25',
            'currency' => 'EGP',
            'per_unit' => 'kg',
            'quoted_on' => '2026-01-01',
            'fx_rate' => null,
            'fx_source' => null,
            'source_id' => Source::factory(),
            'confidence' => Confidence::L7Estimate,
            'created_by' => null,
        ];
    }
}
