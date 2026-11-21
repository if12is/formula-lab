<?php

namespace Tests\Feature\Sanad;

use App\Actions\Sanad\EnqueueSanadMessageAction;
use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

class SanadOutboxTest extends SanadTestCase
{
    /** @param  array<string, mixed>  $payload */
    private function enqueue(array $payload = ['external_id' => 'po-1'], ?SanadDevice $device = null, SanadOutboxKind $kind = SanadOutboxKind::ProductionOrder): SanadOutbox
    {
        return app(EnqueueSanadMessageAction::class)($device ?? $this->device, $kind, $payload);
    }

    private function ack(array $acks, string $key = 'ack-1'): TestResponse
    {
        return $this->postJson('/api/sanad/v1/outbox/ack', ['device_uuid' => null, 'acks' => $acks], $this->headers($key));
    }

    public function test_enqueue_creates_a_pending_row_with_a_prefixed_ulid_and_the_external_id(): void
    {
        $row = $this->enqueue(['external_id' => 'po-2026-000123', 'quantity' => '500.000000']);

        $this->assertMatchesRegularExpression('/^ob_[0-9a-z]{26}$/', $row->id);
        $this->assertSame('po-2026-000123', $row->external_id);
        $this->assertSame(SanadOutboxStatus::Pending, $row->fresh()->status);
        $this->assertSame('500.000000', $row->fresh()->payload['quantity']);
    }

    public function test_enqueue_derives_the_external_id_per_kind(): void
    {
        $this->assertSame('u-1', $this->enqueue(['uuid' => 'u-1'], kind: SanadOutboxKind::FinishedGood)->external_id);
        $this->assertSame('p-1', $this->enqueue(['product_uuid' => 'p-1'], kind: SanadOutboxKind::WholesalePrices)->external_id);
    }

    public function test_enqueue_refuses_floats(): void
    {
        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->enqueue(['external_id' => 'po-1', 'consume' => [['quantity' => 62.5]]]));
        $this->assertSame(0, SanadOutbox::query()->count());
    }

    public function test_a_production_order_is_immutable_once_queued(): void
    {
        $first = $this->enqueue(['external_id' => 'po-1', 'quantity' => '1.0']);

        $this->assertSame($first->id, $this->enqueue(['external_id' => 'po-1', 'quantity' => '1.0'])->id);
        $this->assertThrowsException(SanadLinkException::class, fn () => $this->enqueue(['external_id' => 'po-1', 'quantity' => '2.0']));
        $this->assertSame(1, SanadOutbox::query()->count());
    }

    public function test_the_outbox_pages_in_order_with_a_cursor_and_carries_produced_at(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->enqueue(['external_id' => "po-{$i}"])->id;
        }
        $this->enqueue(['external_id' => 'foreign'], SanadDevice::factory()->for($this->tenant)->create());

        $first = $this->getJson('/api/sanad/v1/outbox?limit=2', $this->headers())->assertOk();
        $first->assertJsonPath('has_more', true)->assertJsonPath('next_cursor', $ids[1]);
        $this->assertSame(array_slice($ids, 0, 2), array_column($first->json('data'), 'id'));
        $item = $first->json('data.0');
        $this->assertSame('production_order', $item['type']);
        $this->assertSame('po-1', $item['external_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['produced_at']);
        $this->assertSame(['external_id' => 'po-1'], $item['payload']);

        $rest = $this->getJson('/api/sanad/v1/outbox?limit=100&cursor='.$ids[1], $this->headers())->assertOk();
        $this->assertSame(array_slice($ids, 2), array_column($rest->json('data'), 'id'));
        $rest->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', $ids[4]);

        $empty = $this->getJson('/api/sanad/v1/outbox?cursor='.$ids[4], $this->headers())->assertOk();
        $empty->assertJsonPath('data', [])->assertJsonPath('next_cursor', $ids[4])->assertJsonPath('has_more', false);
    }

    public function test_reading_is_repeatable_and_the_limit_is_validated(): void
    {
        $this->enqueue();

        $this->assertCount(1, $this->getJson('/api/sanad/v1/outbox', $this->headers())->json('data'));
        $this->assertCount(1, $this->getJson('/api/sanad/v1/outbox', $this->headers())->json('data'));
        $this->getJson('/api/sanad/v1/outbox?limit=0', $this->headers())->assertStatus(422);
        $this->getJson('/api/sanad/v1/outbox?limit=101', $this->headers())->assertStatus(422);
    }

    public function test_acked_items_leave_the_outbox_and_unknown_or_foreign_ids_are_ignored(): void
    {
        $applied = $this->enqueue(['external_id' => 'po-1']);
        $conflict = $this->enqueue(['external_id' => 'po-2']);
        $foreign = $this->enqueue(['external_id' => 'po-3'], SanadDevice::factory()->for($this->tenant)->create());

        $this->ack([
            ['id' => $applied->id, 'status' => 'applied', 'result' => ['consume_status' => 'consumed', 'product_batch_uuid' => 'b1']],
            ['id' => $conflict->id, 'status' => 'conflict'],
            ['id' => 'ob_unknown', 'status' => 'applied'],
            ['id' => $foreign->id, 'status' => 'applied'],
        ])->assertOk()->assertExactJson(['acked' => 2, 'ignored' => 2]);

        $this->assertSame(SanadOutboxStatus::Applied, $applied->fresh()->status);
        $this->assertSame(['consume_status' => 'consumed', 'product_batch_uuid' => 'b1'], $applied->fresh()->result);
        $this->assertNotNull($applied->fresh()->acked_at);
        $this->assertSame(SanadOutboxStatus::Conflict, $conflict->fresh()->status);
        $this->assertSame(SanadOutboxStatus::Pending, $foreign->fresh()->status);
        $this->getJson('/api/sanad/v1/outbox', $this->headers())->assertJsonPath('data', []);
    }

    public function test_acking_twice_with_different_keys_ignores_the_second(): void
    {
        $row = $this->enqueue();
        $this->ack([['id' => $row->id, 'status' => 'applied']], 'a')->assertJson(['acked' => 1]);
        $this->ack([['id' => $row->id, 'status' => 'failed']], 'b')->assertExactJson(['acked' => 0, 'ignored' => 1]);
    }

    public function test_a_failed_item_is_requeued_until_the_failure_limit_then_alerted(): void
    {
        Log::spy();
        $row = $this->enqueue(['external_id' => 'po-9']);
        $error = ['message' => 'insufficient stock', 'code' => 'insufficient_stock'];

        for ($attempt = 1; $attempt < config('sanad.outbox.max_failures'); $attempt++) {
            $this->ack([['id' => $row->id, 'status' => 'failed', 'error' => $error]], "f{$attempt}")->assertJson(['acked' => 1]);

            $retry = SanadOutbox::query()->where('retry_of', $row->id)->firstOrFail();
            $this->assertSame($attempt, $retry->failures);
            $this->assertSame(SanadOutboxStatus::Pending, $retry->status);
            $this->assertSame($row->fresh()->payload, $retry->payload);
            $this->assertEquals($error, $row->fresh()->last_error);
            $this->assertSame(SanadOutboxStatus::Failed, $row->fresh()->status);
            $this->getJson('/api/sanad/v1/outbox?cursor='.$row->id, $this->headers())->assertJsonPath('data.0.id', $retry->id);
            $row = $retry;
        }

        $this->ack([['id' => $row->id, 'status' => 'failed', 'error' => $error]], 'last')->assertJson(['acked' => 1]);
        $this->assertSame(0, SanadOutbox::query()->where('retry_of', $row->id)->count());
        $this->assertSame(config('sanad.outbox.max_failures'), $row->fresh()->failures);
        Log::shouldHaveReceived('alert')->once();
    }

    public function test_ack_validation(): void
    {
        $this->ack([['id' => 'x', 'status' => 'pending']])->assertStatus(422);
        $this->postJson('/api/sanad/v1/outbox/ack', [], $this->headers('v'))->assertStatus(422);
    }
}
