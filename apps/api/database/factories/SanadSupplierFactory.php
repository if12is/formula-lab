<?php

namespace Database\Factories;

use App\Models\SanadSupplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadSupplier> */
class SanadSupplierFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'tenant_id' => Tenant::factory(),
            'code' => null,
            'name' => 'Supplier '.fake()->unique()->bothify('??##'),
            'name_en' => null,
            'tax_number' => null,
            'payment_terms' => 30,
            'is_active' => true,
            'updated_at' => '2026-11-21 09:30:00+00',
            'deleted' => false,
            'synced_at' => now(),
        ];
    }
}
