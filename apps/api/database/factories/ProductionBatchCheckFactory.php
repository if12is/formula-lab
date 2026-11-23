<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatchCheck> */
class ProductionBatchCheckFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'step_no' => 1,
            'check_no' => 1,
            'property' => 'ph',
            'value' => '7',
            'unit' => 'pH',
            'status' => 'UNKNOWN',
            'measured_by' => 'Operator A',
            'measured_at' => '2026-11-25 10:00:00',
        ];
    }
}
