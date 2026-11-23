<?php

namespace Database\Factories;

use App\Enums\Production\ProductionBatchStatus;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatch> */
class ProductionBatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'production_order_id' => ProductionOrder::factory()->approved(),
            'tenant_id' => fn (array $attributes): string => ProductionOrder::query()->findOrFail($attributes['production_order_id'])->tenant_id,
            'batch_number' => fn (array $attributes): string => ProductionOrder::query()->findOrFail($attributes['production_order_id'])->batch_number,
            'formula_version_id' => fn (array $attributes): string => ProductionOrder::query()->findOrFail($attributes['production_order_id'])->formula_version_id,
            'formula_hash' => fn (array $attributes): string => ProductionOrder::query()->findOrFail($attributes['production_order_id'])->formula_hash,
            'snapshot' => fn (array $attributes): array => ProductionOrder::query()->findOrFail($attributes['production_order_id'])->snapshot,
            'status' => ProductionBatchStatus::InProgress,
            'operator' => 'Operator A',
            'started_at' => '2026-11-25 08:00:00',
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => ProductionBatchStatus::Completed, 'completed_at' => '2026-11-25 16:00:00']);
    }
}
