<?php

namespace Database\Factories;

use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SanadOutbox> */
class SanadOutboxFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => config('sanad.outbox.id_prefix').Str::lower((string) Str::ulid()),
            'device_id' => SanadDevice::factory(),
            'kind' => SanadOutboxKind::ProductionOrder,
            'external_id' => 'po-'.fake()->unique()->numerify('######'),
            'payload' => ['external_id' => 'po-x'],
            'status' => SanadOutboxStatus::Pending,
            'failures' => 0,
            'created_at' => now(),
        ];
    }
}
