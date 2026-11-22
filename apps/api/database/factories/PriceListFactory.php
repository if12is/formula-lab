<?php

namespace Database\Factories;

use App\Enums\PriceListSyncStatus;
use App\Models\PriceList;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceList> */
class PriceListFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'List '.fake()->unique()->bothify('??##'),
            'currency' => 'EGP',
            'is_active' => true,
            'sync_status' => PriceListSyncStatus::Draft,
        ];
    }
}
