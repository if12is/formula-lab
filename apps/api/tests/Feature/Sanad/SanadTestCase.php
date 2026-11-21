<?php

namespace Tests\Feature\Sanad;

use App\Models\SanadDevice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class SanadTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'fls_test-token';

    protected Tenant $tenant;

    protected SanadDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->device = SanadDevice::factory()->for($this->tenant)->withToken(self::TOKEN)->create();
    }

    /** @return array<string, string> */
    protected function headers(?string $key = null, ?string $token = self::TOKEN): array
    {
        return array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Idempotency-Key' => $key,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function item(string $entity, string $uuid, array $payload, string $updatedAt = '2026-11-21T09:30:00Z', string $op = 'upsert'): array
    {
        return ['entity' => $entity, 'uuid' => $uuid, 'op' => $op, 'updated_at' => $updatedAt, 'payload' => $payload];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function push(array $items, ?string $key = null, ?string $cursor = 'cursor-1'): TestResponse
    {
        return $this->postJson('/api/sanad/v1/changes', ['cursor' => $cursor, 'items' => $items], $this->headers($key ?? (string) Str::uuid()));
    }
}
