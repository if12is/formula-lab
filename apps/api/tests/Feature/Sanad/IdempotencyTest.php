<?php

namespace Tests\Feature\Sanad;

use App\Actions\Sanad\PruneIdempotencyKeysAction;
use App\Models\SanadDevice;
use App\Support\RequestFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

class IdempotencyTest extends SanadTestCase
{
    private function ack(string $key, array $acks = []): TestResponse
    {
        return $this->postJson('/api/sanad/v1/outbox/ack', ['acks' => $acks], $this->headers($key));
    }

    public function test_a_missing_key_is_400(): void
    {
        $this->ack('')->assertStatus(400);
        $this->postJson('/api/sanad/v1/outbox/ack', ['acks' => []], $this->headers())->assertStatus(400);
    }

    public function test_a_key_longer_than_255_characters_is_400(): void
    {
        $this->ack(str_repeat('k', 256))->assertStatus(400);
    }

    public function test_any_opaque_key_up_to_255_characters_is_accepted(): void
    {
        $keys = [
            hash('sha256', '{"acks":[]}'),
            str_repeat('k', 255),
            'not a uuid / with spaces & مفتاح',
            'po-2026-000123',
        ];

        foreach ($keys as $key) {
            $this->ack($key)->assertOk()->assertHeaderMissing('Idempotent-Replay');
            $this->ack($key)->assertOk()->assertHeader('Idempotent-Replay', 'true');
        }
    }

    public function test_a_replay_returns_the_stored_response_with_the_header_and_runs_nothing_again(): void
    {
        $first = $this->push([$this->item('supplier', '11111111-1111-4111-8111-111111111111', ['name' => 'A'])], 'key-1');
        $first->assertOk()->assertHeaderMissing('Idempotent-Replay');

        $replay = $this->push([$this->item('supplier', '11111111-1111-4111-8111-111111111111', ['name' => 'A'])], 'key-1');
        $replay->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, DB::table('sanad_suppliers_cache')->count());
    }

    public function test_a_replay_ignores_json_key_order(): void
    {
        $this->postJson('/api/sanad/v1/outbox/ack', ['acks' => [], 'device_uuid' => null], $this->headers('k'))->assertOk();
        $this->postJson('/api/sanad/v1/outbox/ack', ['device_uuid' => null, 'acks' => []], $this->headers('k'))->assertOk()->assertHeader('Idempotent-Replay', 'true');
    }

    public function test_the_same_key_with_a_different_body_is_422(): void
    {
        $this->ack('key-2', [])->assertOk();

        $this->ack('key-2', [['id' => 'ob_x', 'status' => 'applied']])->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_the_same_key_on_another_token_or_path_is_independent(): void
    {
        $other = SanadDevice::factory()->for($this->tenant)->withToken('other')->create();
        $this->ack('shared')->assertOk();

        $this->postJson('/api/sanad/v1/outbox/ack', ['acks' => []], $this->headers('shared', 'other'))->assertOk()->assertHeaderMissing('Idempotent-Replay');
        $this->push([], 'shared')->assertOk()->assertHeaderMissing('Idempotent-Replay');
        $this->assertNotNull($other);
    }

    public function test_a_request_still_in_flight_is_409_with_retry_after(): void
    {
        $scope = hash('sha256', implode('|', [self::TOKEN, 'POST', 'api/sanad/v1/outbox/ack']));
        DB::table('idempotency_keys')->insert([
            'key' => 'busy', 'scope' => $scope, 'request_hash' => RequestFingerprint::hash(['acks' => []]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ack('busy')->assertStatus(409)->assertHeader('Retry-After', '1');
    }

    public function test_an_abandoned_in_flight_key_is_taken_over_after_the_timeout(): void
    {
        $scope = hash('sha256', implode('|', [self::TOKEN, 'POST', 'api/sanad/v1/outbox/ack']));
        DB::table('idempotency_keys')->insert([
            'key' => 'dead', 'scope' => $scope, 'request_hash' => RequestFingerprint::hash(['acks' => []]),
            'created_at' => now()->subSeconds(config('sanad.idempotency.in_flight_timeout_seconds') + 5), 'updated_at' => now(),
        ]);

        $this->ack('dead')->assertOk()->assertHeaderMissing('Idempotent-Replay');
    }

    public function test_a_validation_error_is_stored_but_a_server_error_is_not(): void
    {
        $bad = $this->postJson('/api/sanad/v1/changes', ['items' => 'nope'], $this->headers('bad'));
        $bad->assertStatus(422);
        $this->postJson('/api/sanad/v1/changes', ['items' => 'nope'], $this->headers('bad'))->assertStatus(422)->assertHeader('Idempotent-Replay', 'true');

        Route::middleware(['sanad.device', 'idempotent'])->post('/api/__test/boom', function () {
            throw new \RuntimeException('boom');
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/__test/boom', [], $this->headers('boom'));
            $this->fail('expected exception');
        } catch (\RuntimeException) {
            $this->assertDatabaseMissing('idempotency_keys', ['key' => 'boom']);
        }
    }

    public function test_pruning_deletes_only_keys_older_than_the_retention(): void
    {
        $old = now()->subDays(config('sanad.idempotency.retention_days') + 1);
        DB::table('idempotency_keys')->insert([
            ['key' => 'old', 'scope' => 's', 'request_hash' => 'h', 'created_at' => $old, 'updated_at' => $old],
            ['key' => 'new', 'scope' => 's', 'request_hash' => 'h', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(1, app(PruneIdempotencyKeysAction::class)());
        $this->assertSame(['new'], DB::table('idempotency_keys')->pluck('key')->all());
        $this->artisan('idempotency:prune')->assertSuccessful();
    }
}
