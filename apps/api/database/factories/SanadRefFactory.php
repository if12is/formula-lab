<?php

namespace Database\Factories;

use App\Enums\Sanad\SanadRefType;
use App\Models\SanadRef;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadRef> */
class SanadRefFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'entity_type' => SanadRefType::Product,
            'sanad_uuid' => fake()->uuid(),
            'local_table' => null,
            'local_id' => null,
            'last_synced_at' => now(),
            'payload_hash' => fake()->sha256(),
            'sanad_updated_at' => now(),
            'deleted_at' => null,
        ];
    }
}
