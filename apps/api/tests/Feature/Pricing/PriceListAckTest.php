<?php

namespace Tests\Feature\Pricing;

use App\Actions\Pricing\PublishPriceListToSanadAction;
use App\Enums\PriceListSyncStatus;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\PriceList;
use App\Models\SanadOutbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPriceLists;
use Tests\Feature\Sanad\SanadTestCase;

/**
 * Sanad's verdict on a `price_list_upsert` item (contract 3.2 / 8.6) reaches the price list: applied with Sanad's counts,
 * failed with its error code (re-queued under a new id, at most five times), conflict (not re-queued, a person decides).
 */
class PriceListAckTest extends SanadTestCase
{
    use BuildsPriceLists;

    private PriceList $list;

    private SanadOutbox $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defineRules();
        $this->list = PriceList::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->ruleItem($this->list, $this->costedFormula($this->tenant->id));
        $this->row = SanadOutbox::query()->findOrFail(app(PublishPriceListToSanadAction::class)($this->list, $this->device)->outboxId);
    }

    /** @param  array<string, mixed>  $ack */
    private function ack(array $ack, string $key = 'ack-1'): TestResponse
    {
        return $this->postJson('/api/sanad/v1/outbox/ack', ['device_uuid' => null, 'acks' => [$ack]], $this->headers($key));
    }

    public function test_applied_marks_the_list_applied_with_sanads_counts(): void
    {
        $result = ['uuid' => $this->list->id, 'created' => true, 'items_upserted' => 1, 'items_removed' => 0, 'customers_assigned' => 0];

        $this->ack(['id' => $this->row->id, 'status' => 'applied', 'result' => $result])->assertOk()->assertJson(['acked' => 1]);

        $list = $this->list->fresh();
        $this->assertSame(PriceListSyncStatus::Applied, $list->sync_status);
        $this->assertSame(1, $list->sync_detail['items_upserted']);
        $this->assertSame($this->row->id, $list->sync_outbox_id);
    }

    public function test_failed_marks_the_list_failed_and_follows_the_requeued_row(): void
    {
        $error = ['message' => 'العميل غير موجود في سند', 'code' => 'unknown_customer'];

        $this->ack(['id' => $this->row->id, 'status' => 'failed', 'error' => $error]);

        $requeued = SanadOutbox::query()->where('retry_of', $this->row->id)->firstOrFail();
        $list = $this->list->fresh();
        $this->assertSame(PriceListSyncStatus::Failed, $list->sync_status);
        $this->assertSame('unknown_customer', $list->sync_detail['error']['code']);
        $this->assertTrue($list->sync_detail['requeued']);
        $this->assertSame($requeued->id, $list->sync_outbox_id, 'the list follows the copy Sanad will pull next');
        $this->assertSame($this->row->payload, $requeued->payload);

        $this->ack(['id' => $requeued->id, 'status' => 'applied', 'result' => ['uuid' => $this->list->id, 'created' => true, 'items_upserted' => 1, 'items_removed' => 0, 'customers_assigned' => 0]], 'ack-2');
        $this->assertSame(PriceListSyncStatus::Applied, $this->list->fresh()->sync_status);
    }

    public function test_after_the_fifth_failure_nothing_is_requeued_and_the_list_stays_failed(): void
    {
        Log::spy();
        $row = $this->row;
        foreach (range(1, 5) as $attempt) {
            $this->ack(['id' => $row->id, 'status' => 'failed', 'error' => ['message' => 'x', 'code' => 'validation']], "ack-{$attempt}");
            $next = SanadOutbox::query()->where('retry_of', $row->id)->first();
            if ($next === null) {
                break;
            }
            $row = $next;
        }

        $this->assertSame(5, SanadOutbox::query()->where('status', SanadOutboxStatus::Failed)->count());
        $this->assertSame(0, SanadOutbox::query()->where('status', SanadOutboxStatus::Pending)->count());
        $list = $this->list->fresh();
        $this->assertSame(PriceListSyncStatus::Failed, $list->sync_status);
        $this->assertFalse($list->sync_detail['requeued']);
        Log::shouldHaveReceived('alert')->once();
    }

    public function test_conflict_marks_the_list_conflict_and_is_not_requeued(): void
    {
        $this->ack(['id' => $this->row->id, 'status' => 'conflict', 'result' => ['reason' => 'local_price_list_changed']]);

        $list = $this->list->fresh();
        $this->assertSame(PriceListSyncStatus::Conflict, $list->sync_status);
        $this->assertSame('local_price_list_changed', $list->sync_detail['reason']);
        $this->assertSame(1, SanadOutbox::query()->count());
    }

    public function test_a_failed_or_conflicted_list_is_sent_again_when_published_again(): void
    {
        $this->ack(['id' => $this->row->id, 'status' => 'conflict', 'result' => ['reason' => 'local_price_list_changed']]);

        $result = app(PublishPriceListToSanadAction::class)($this->list->fresh(), $this->device);

        $this->assertFalse($result->unchanged, 'the same content is sent again after a conflict, a person has decided');
        $this->assertNotSame($this->row->id, $result->outboxId);
        $this->assertSame(PriceListSyncStatus::Pending, $this->list->fresh()->sync_status);
    }

    public function test_an_ack_for_a_publication_a_newer_one_replaced_does_not_overwrite_the_list(): void
    {
        $this->list->items()->first()->formula->update(['unit_cost' => '36.00']);
        $newer = SanadOutbox::query()->findOrFail(app(PublishPriceListToSanadAction::class)($this->list->fresh(), $this->device)->outboxId);

        $this->ack(['id' => $this->row->id, 'status' => 'failed', 'error' => ['message' => 'old', 'code' => 'validation']]);

        $list = $this->list->fresh();
        $this->assertSame(PriceListSyncStatus::Pending, $list->sync_status, 'the old row is not the current publication');
        $this->assertSame($newer->id, $list->sync_outbox_id);
    }

    public function test_an_ack_of_another_kind_leaves_price_lists_alone(): void
    {
        $other = SanadOutbox::factory()->create(['device_id' => $this->device->id]);

        $this->ack(['id' => $other->id, 'status' => 'applied', 'result' => []])->assertOk();

        $this->assertSame(PriceListSyncStatus::Pending, $this->list->fresh()->sync_status);
    }
}
