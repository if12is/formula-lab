<?php

namespace Tests\Feature\Production;

use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\SanadOutbox;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\ProductionTraceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** Q-05: which batches used a raw-material lot, with the order, the formula version and what Sanad answered; sales stay in Sanad. */
class ProductionTraceTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private ProductionBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionBatchService::class);
    }

    private function batchOn(string $day, string $surfactantLot, string $saltLot): ProductionBatch
    {
        $order = app(ProductionOrderService::class)->create($this->version, $this->orderData(['planned_on' => $day]));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $batch = $this->service->start($order, 'Operator A', null);
        foreach ([['Surfactant X', '300', $surfactantLot], ['Salt', '38', $saltLot]] as [$name, $actual, $lot]) {
            $line = $batch->lines()->get()->first(fn (ProductionBatchLine $line): bool => $line->tradeMaterial->trade_name === $name);
            $this->service->weigh($batch, $line, ['actual_quantity' => $actual, 'lot_number' => $lot, 'weighed_by' => 'Operator A']);
        }

        return $batch;
    }

    public function test_a_lot_is_traced_to_every_batch_that_used_it_and_only_those(): void
    {
        $this->productionProduct();
        $first = $this->batchOn('2026-11-25', 'L-7731', 'S-1');
        $second = $this->batchOn('2026-11-26', 'L-7731', 'S-2');
        $this->batchOn('2026-11-27', 'L-9999', 'S-1');

        $rows = app(ProductionTraceService::class)->byLot('L-7731');

        $this->assertSame([$first->batch_number, $second->batch_number], array_column(array_column($rows, 'batch'), 'number'));
        $this->assertSame(['Surfactant X', 'Surfactant X'], array_column($rows, 'material'));
        $this->assertSame(['300', '300'], array_column($rows, 'quantity'));
        $this->assertSame([$this->formula->code, 1], [$rows[0]['formula']['code'], $rows[0]['formula']['version']]);
        $this->assertCount(2, app(ProductionTraceService::class)->byLot('S-1'));
        $this->assertSame([], app(ProductionTraceService::class)->byLot('NOT-USED'));
        $this->assertSame([], app(ProductionTraceService::class)->byLot('   '));
    }

    public function test_the_material_filter_narrows_a_lot_that_two_materials_share(): void
    {
        $this->productionProduct();
        $this->batchOn('2026-11-25', 'SHARED', 'SHARED');

        $this->assertCount(2, app(ProductionTraceService::class)->byLot('SHARED'));
        $this->assertSame(['Salt'], array_column(app(ProductionTraceService::class)->byLot('SHARED', $this->salt->id), 'material'));
    }

    public function test_the_finished_good_batch_sanad_answered_is_the_reference_for_the_sales_lookup(): void
    {
        $this->productionProduct();
        $batch = $this->batchOn('2026-11-25', 'L-7731', 'S-1');
        $order = $batch->order;
        $this->assertNull(app(ProductionTraceService::class)->byLot('L-7731')[0]['sanad']['status'], 'Not sent yet.');

        $row = SanadOutbox::factory()->create(['device_id' => $this->device->id, 'kind' => 'production_order', 'external_id' => $order->code, 'payload' => ['external_id' => $order->code], 'status' => SanadOutboxStatus::Applied, 'result' => ['consume_status' => 'consumed', 'receive_status' => 'received', 'product_batch_uuid' => 'fg-batch-1']]);
        $order->forceFill(['status' => ProductionOrderStatus::Sent, 'sent_at' => now(), 'sanad_outbox_id' => $row->id])->save();

        $sanad = app(ProductionTraceService::class)->byLot('L-7731')[0]['sanad'];
        $this->assertSame(['applied', 'fg-batch-1'], [$sanad['status'], $sanad['finished_good_batch']]);
    }

    public function test_the_trace_page_answers_a_search_and_names_the_empty_cases(): void
    {
        $this->productionProduct();
        $this->batchOn('2026-11-25', 'L-7731', 'S-1');

        $this->get(route('production.trace'))->assertOk()->assertInertia(fn ($page) => $page->component('Production/Trace/Index')->where('searched', false)->where('rows', []));
        $this->get(route('production.trace', ['lot' => 'L-7731']))->assertInertia(fn ($page) => $page->where('searched', true)->has('rows', 1)->where('rows.0.material', 'Surfactant X'));
        $this->get(route('production.trace', ['lot' => 'nope']))->assertInertia(fn ($page) => $page->where('searched', true)->where('rows', []));
        $this->get(route('production.trace', ['lot' => 'L-7731', 'material' => $this->salt->id]))->assertInertia(fn ($page) => $page->where('rows', [])->where('material', 'Salt'));
    }

    public function test_the_batch_page_links_each_lot_to_its_trace(): void
    {
        $this->productionProduct();
        $batch = $this->batchOn('2026-11-25', 'L-7731', 'S-1');

        $props = $this->pageProps($this->get(route('production.batches.show', $batch))->assertOk());

        $this->assertSame(['L-7731', 'S-1'], array_column($props['lots'], 'lot'));
        $this->assertSame(route('production.trace', ['lot' => 'L-7731', 'material' => $this->surfactant->id], false), $props['lots'][0]['href']);
    }
}
