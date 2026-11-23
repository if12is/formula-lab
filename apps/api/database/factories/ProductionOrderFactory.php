<?php

namespace Database\Factories;

use App\Enums\Production\ProductionOrderStatus;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Support\Production\ProductionSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionOrder> */
class ProductionOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_version_id' => FormulaVersion::factory()->approved(),
            'tenant_id' => fn (array $attributes): string => FormulaVersion::query()->with('formula')->findOrFail($attributes['formula_version_id'])->formula->tenant_id,
            'code' => 'PO-2026-'.fake()->unique()->numerify('####'),
            'batch_number' => 'B-261125-'.fake()->unique()->numerify('##'),
            'formula_hash' => fn (array $attributes): string => FormulaVersion::query()->findOrFail($attributes['formula_version_id'])->hash,
            'snapshot' => fn (array $attributes): array => ProductionSnapshot::build(FormulaVersion::query()->findOrFail($attributes['formula_version_id']), []),
            'batch_size' => '100',
            'batch_unit' => 'kg',
            'scale_resolution_g' => '0.1',
            'units_count' => null,
            'planned_on' => '2026-11-25',
            'status' => ProductionOrderStatus::Draft,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ProductionOrderStatus::Approved, 'approved_at' => '2026-11-24 09:00:00']);
    }
}
