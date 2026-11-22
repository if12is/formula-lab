<?php

namespace Database\Factories;

use App\Models\SanadCustomer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadCustomer> */
class SanadCustomerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CLT-'.fake()->unique()->numerify('####'),
            'name' => 'Customer '.fake()->unique()->bothify('??##'),
            'name_en' => null,
            'is_active' => true,
            'is_wholesale' => false,
            'price_list_uuid' => null,
            'updated_at' => '2026-11-21 09:30:00+00',
            'deleted' => false,
            'synced_at' => now(),
        ];
    }
}
