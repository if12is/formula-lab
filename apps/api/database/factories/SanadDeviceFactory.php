<?php

namespace Database\Factories;

use App\Enums\Sanad\SanadAbility;
use App\Models\SanadDevice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SanadDevice> */
class SanadDeviceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Sanad '.fake()->unique()->bothify('??##'),
            'token_hash' => SanadDevice::hashToken(fake()->unique()->sha256()),
            'abilities' => SanadAbility::values(),
            'sanad_device_uuid' => null,
            'last_seen_at' => null,
            'push_cursor' => null,
            'pull_cursor' => null,
        ];
    }

    /** Stores the hash of a known plain token so tests can send it. */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => SanadDevice::hashToken($token)]);
    }

    /** @param  list<SanadAbility>  $abilities */
    public function abilities(array $abilities): static
    {
        return $this->state(['abilities' => array_map(fn (SanadAbility $ability): string => $ability->value, $abilities)]);
    }
}
