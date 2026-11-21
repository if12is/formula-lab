<?php

namespace Database\Factories;

use App\Enums\Sanad\SanadItemKind;
use App\Models\SanadProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadProduct> */
class SanadProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'tenant_id' => Tenant::factory(),
            'name' => 'Product '.fake()->unique()->bothify('??##'),
            'name_en' => null,
            'sku' => null,
            'barcode' => null,
            'item_kind' => SanadItemKind::RawMaterial,
            'is_sellable' => false,
            'uom' => 'kg',
            'units' => [],
            'cost_price' => '41.20',
            'tax_rate' => '14.00',
            'updated_at' => '2026-11-21 09:30:00+00',
            'deleted' => false,
            'synced_at' => now(),
        ];
    }

    public function packaging(): static
    {
        return $this->state(['item_kind' => SanadItemKind::Packaging, 'uom' => 'unit']);
    }

    public function deleted(): static
    {
        return $this->state(['deleted' => true]);
    }
}
