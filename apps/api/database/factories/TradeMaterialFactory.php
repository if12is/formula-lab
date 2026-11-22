<?php

namespace Database\Factories;

use App\Enums\ItemKind;
use App\Enums\TradeMaterialStatus;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradeMaterial> */
class TradeMaterialFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'substance_id' => Substance::factory(),
            'sanad_product_uuid' => null,
            'sanad_supplier_uuid' => null,
            'trade_name' => 'Material '.fake()->unique()->bothify('??##'),
            'grade' => null,
            'function' => null,
            'is_mixture' => false,
            'status' => TradeMaterialStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => TradeMaterialStatus::Active]);
    }

    /** A packaging component of the given kind (no substance); pass `spec` attributes to record its spec. */
    public function packaging(ItemKind $kind = ItemKind::Container): static
    {
        return $this->state(['substance_id' => null, 'item_kind' => $kind, 'status' => TradeMaterialStatus::Active]);
    }

    public function mixture(): static
    {
        return $this->state(['substance_id' => null, 'is_mixture' => true]);
    }
}
