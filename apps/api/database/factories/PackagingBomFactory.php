<?php

namespace Database\Factories;

use App\Models\PackagingBom;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackagingBom> */
class PackagingBomFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'name' => 'SKU '.fake()->unique()->bothify('??-###'),
            'sku_code' => null,
            'sanad_product_uuid' => null,
            'units_per_carton' => null,
            'cartons_per_pallet' => null,
            'note' => null,
        ];
    }

    /** The packing configuration a carton or pallet line needs. */
    public function packed(int $unitsPerCarton, ?int $cartonsPerPallet = null): static
    {
        return $this->state(['units_per_carton' => $unitsPerCarton, 'cartons_per_pallet' => $cartonsPerPallet]);
    }
}
