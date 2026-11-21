<?php

namespace Database\Factories;

use App\Models\SanadPurchaseLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadPurchaseLine> */
class SanadPurchaseLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sanad_uuid' => fake()->uuid(),
            'tenant_id' => Tenant::factory(),
            'sanad_purchase_uuid' => fake()->uuid(),
            'sanad_supplier_uuid' => fake()->uuid(),
            'sanad_product_uuid' => fake()->uuid(),
            'purchase_date' => '2026-11-20',
            'purchase_status' => 'received',
            'quantity' => '2.00',
            'purchase_uom' => 'jerrycan 25 kg',
            'base_quantity' => '50.00',
            'received_quantity' => '2.00',
            'unit_cost' => '1287.50',
            'discount_amount' => '0.00',
            'tax_amount' => '360.50',
            'subtotal' => '2575.00',
            'currency' => 'EGP',
            'sanad_updated_at' => '2026-11-21 09:30:00+00',
            'synced_at' => now(),
        ];
    }
}
