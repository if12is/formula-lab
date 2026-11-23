<?php

namespace Database\Factories;

use App\Enums\Production\BatchLineKind;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatchLine> */
class ProductionBatchLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'line_no' => 1,
            'kind' => BatchLineKind::RawMaterial,
            'trade_material_id' => TradeMaterial::factory(),
            'phase' => null,
            'uom' => 'g',
            'planned_quantity' => '1000',
            'weigh_quantity' => '1000',
        ];
    }
}
