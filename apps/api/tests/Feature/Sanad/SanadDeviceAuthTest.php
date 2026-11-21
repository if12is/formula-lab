<?php

namespace Tests\Feature\Sanad;

use App\Enums\Sanad\SanadAbility;
use App\Models\SanadDevice;
use App\Models\Tenant;
use Illuminate\Support\Str;

class SanadDeviceAuthTest extends SanadTestCase
{
    public function test_a_missing_token_is_401(): void
    {
        $this->getJson('/api/sanad/v1/outbox', $this->headers(token: null))->assertStatus(401)->assertJsonStructure(['message']);
    }

    public function test_an_unknown_token_is_401(): void
    {
        $this->getJson('/api/sanad/v1/outbox', $this->headers(token: 'fls_wrong'))->assertStatus(401);
    }

    public function test_a_token_without_the_ability_is_403(): void
    {
        SanadDevice::factory()->for($this->tenant)->withToken('read-only')->abilities([SanadAbility::OutboxRead])->create();

        $this->getJson('/api/sanad/v1/outbox', $this->headers(token: 'read-only'))->assertOk();
        $this->postJson('/api/sanad/v1/changes', ['items' => []], $this->headers('k1', 'read-only'))->assertStatus(403);
        $this->postJson('/api/sanad/v1/outbox/ack', ['acks' => []], $this->headers('k2', 'read-only'))->assertStatus(403);
    }

    public function test_a_valid_token_authenticates_and_records_last_seen(): void
    {
        $this->assertNull($this->device->last_seen_at);

        $this->getJson('/api/sanad/v1/outbox', $this->headers())->assertOk();

        $this->assertNotNull($this->device->fresh()->last_seen_at);
    }

    public function test_the_first_device_uuid_header_binds_the_token_and_another_one_is_403(): void
    {
        $sanadUuid = '6f1c0000-0000-4000-8000-000000000001';

        $this->getJson('/api/sanad/v1/outbox', $this->headers() + ['X-Device-Uuid' => $sanadUuid])->assertOk();
        $this->assertSame($sanadUuid, $this->device->fresh()->sanad_device_uuid);

        $this->getJson('/api/sanad/v1/outbox', $this->headers() + ['X-Device-Uuid' => $sanadUuid])->assertOk();
        $this->getJson('/api/sanad/v1/outbox', $this->headers() + ['X-Device-Uuid' => '6f1c0000-0000-4000-8000-000000000002'])->assertStatus(403);
        $this->getJson('/api/sanad/v1/outbox', $this->headers() + ['X-Device-Uuid' => 'not-a-uuid'])->assertStatus(400);
    }

    public function test_the_device_create_command_prints_the_token_once_and_stores_only_its_hash(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('sanad:device:create', ['name' => 'Factory POS', '--tenant' => $tenant->id, '--ability' => ['outbox:read']])
            ->expectsOutputToContain('fls_')
            ->assertSuccessful();

        $device = SanadDevice::query()->where('name', 'Factory POS')->firstOrFail();
        $this->assertSame(['outbox:read'], $device->abilities);
        $this->assertSame(64, strlen($device->token_hash));
        $this->assertStringNotContainsString('fls_', $device->token_hash);
        $this->assertArrayNotHasKey('token_hash', $device->toArray());
    }

    public function test_the_device_create_command_needs_a_resolvable_tenant_and_known_abilities(): void
    {
        Tenant::factory()->count(2)->create();

        $this->artisan('sanad:device:create', ['name' => 'X'])->assertFailed();
        $this->artisan('sanad:device:create', ['name' => 'X', '--tenant' => (string) Str::uuid()])->assertFailed();
        $this->artisan('sanad:device:create', ['name' => 'X', '--tenant' => Tenant::query()->value('id'), '--ability' => ['nope']])->assertFailed();
    }

    public function test_a_single_tenant_is_picked_automatically(): void
    {
        $this->artisan('sanad:device:create', ['name' => 'Auto'])->assertSuccessful();

        $this->assertSame($this->tenant->id, SanadDevice::query()->where('name', 'Auto')->value('tenant_id'));
    }
}
