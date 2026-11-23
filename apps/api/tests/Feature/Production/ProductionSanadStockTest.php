<?php

namespace Tests\Feature\Production;

use App\Actions\Sanad\AcknowledgeSanadOutboxItemAction;
use App\Enums\ItemKind;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadDeliveryStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use App\Models\ProductionOrder;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Costing\FinishedGoodPublishService;
use App\Services\Production\ProductionOrderService;
use App\Support\Sanad\SanadDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\fakes\sanad\SanadFake;
use Tests\TestCase;

/**
 * Q-08: the stock effect of an approved production order (consume the materials and packaging, receive the finished good with the
 * approved unit cost) is Sanad's. Queued (outbox) or direct (LAN), what Sanad answered is shown on the order and nothing here moves stock.
 */
class ProductionSanadStockTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private ProductionOrderService $service;

    private TradeMaterial $bottle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionOrderService::class);
    }

    private function approvedOrder(): ProductionOrder
    {
        $version = $this->productionProduct();
        $this->bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Bottle', 'sanad_product_uuid' => (string) Str::uuid()]);
        $order = $this->service->create($version, $this->orderData(['packaging' => [['trade_material_id' => $this->bottle->id, 'quantity' => '505']]]));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        return $order;
    }

    /** The order with no queue link, so it goes to Sanad directly over the LAN fake. */
    private function lanOrder(): ProductionOrder
    {
        Sleep::fake();
        $order = $this->approvedOrder();
        SanadDevice::query()->delete();
        SanadFake::use();

        return $order;
    }

    private function approver(): User
    {
        config(['production.approver_roles' => ['quality_manager']]);

        return User::factory()->create(['role' => 'quality_manager']);
    }

    public function test_the_queued_message_consumes_every_material_and_packaging_line_and_receives_the_finished_good_with_the_approved_cost(): void
    {
        $order = $this->approvedOrder();

        $payload = SanadOutbox::query()->findOrFail($this->service->send($order)->sanad_outbox_id)->payload;

        $this->assertSame(
            ['raw_material', 'raw_material', 'raw_material', 'packaging'],
            array_column($payload['consume'], 'kind'),
        );
        $this->assertSame($this->bottle->sanad_product_uuid, $payload['consume'][3]['product_uuid']);
        $this->assertSame(
            ['finished_good_uuid' => $this->formula->sanad_product_uuid, 'quantity' => '500.000000', 'unit_cost' => '18.457300'],
            ['finished_good_uuid' => $payload['finished_good_uuid'], 'quantity' => $payload['receive']['quantity'], 'unit_cost' => $payload['receive']['unit_cost']],
        );
    }

    public function test_the_order_screen_says_queued_then_retrying_then_needs_review_after_the_allowed_refusals(): void
    {
        $order = $this->approvedOrder();
        $sent = $this->service->send($order);
        $max = config('sanad.outbox.max_failures');
        $delivery = fn () => SanadDelivery::fromOutbox($sent->fresh()->latestOutbox(), confirmsStock: true);
        $this->assertSame(SanadDeliveryStatus::Queued, $delivery()->status);

        $device = SanadDevice::query()->firstOrFail();
        for ($failure = 1; $failure <= $max; $failure++) {
            $row = $sent->fresh()->latestOutbox();
            app(AcknowledgeSanadOutboxItemAction::class)($device, ['id' => $row->id, 'status' => 'failed', 'error' => ['message' => 'الرصيد غير كافٍ', 'code' => 'insufficient_stock']]);
            $expected = $failure < $max ? SanadDeliveryStatus::Retrying : SanadDeliveryStatus::NeedsReview;
            $this->assertSame($expected, $delivery()->status, "after failure {$failure}");
        }

        $this->actingAs($this->approver())->get(route('production.orders.show', $sent))->assertInertia(fn (Assert $page) => $page
            ->where('sanad.mode', 'outbox')->where('sanad.delivery.status', 'needs_review')->where('sanad.delivery.needs_attention', true)
            ->where('sanad.delivery.failures', $max)->where('sanad.delivery.max_failures', $max)->where('sanad.delivery.error.code', 'insufficient_stock'));
        $this->assertSame($max, SanadOutbox::query()->where('kind', SanadOutboxKind::ProductionOrder)->count());
    }

    public function test_only_consumed_and_received_together_confirm_the_stock_effect(): void
    {
        $order = $this->approvedOrder();
        $row = SanadOutbox::query()->findOrFail($this->service->send($order)->sanad_outbox_id);
        $status = function (array $result) use ($row): SanadDeliveryStatus {
            $row->forceFill(['status' => SanadOutboxStatus::Applied, 'result' => $result])->save();

            return SanadDelivery::fromOutbox($row->fresh(), confirmsStock: true)->status;
        };

        $this->assertSame(SanadDeliveryStatus::Confirmed, $status(['consume_status' => 'consumed', 'receive_status' => 'received']));
        $this->assertSame(SanadDeliveryStatus::Partial, $status(['consume_status' => 'consumed']));
        $this->assertSame(SanadDeliveryStatus::Applied, $status([]));
        $this->assertSame(SanadDeliveryStatus::Conflict, SanadDelivery::fromOutbox($row->forceFill(['status' => SanadOutboxStatus::Conflict]), true)->status);
    }

    public function test_direct_mode_consumes_then_receives_and_keeps_what_sanad_answered_on_the_order(): void
    {
        $order = $this->lanOrder();
        SanadFake::consume();
        SanadFake::receive();

        $sent = $this->service->send($order);

        $this->assertSame(ProductionOrderStatus::Sent, $sent->status);
        $this->assertSame(0, SanadOutbox::query()->count());
        $this->assertSame(['consumed', '2575.00', 'received', '00000000-0000-4000-8000-0000000000b1', 1], [
            $sent->sanad_sync['consume']['status'], $sent->sanad_sync['consume']['total_cost'],
            $sent->sanad_sync['receive']['status'], $sent->sanad_sync['receive']['product_batch_uuid'], $sent->sanad_sync['attempts'],
        ]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/production-orders/{$order->code}/consume")
            && $request->hasHeader('Idempotency-Key')
            && $request['branch_code'] === null && $request['consumed_on'] === '2026-11-25'
            && array_column($request['lines'], 'uom') === ['kg', 'kg', 'kg', 'unit']
            && array_column($request['lines'], 'quantity') === ['2.162500', '0.300000', '0.037500', '505.000000']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/production-orders/{$order->code}/receive")
            && $request['finished_good_uuid'] === $this->formula->sanad_product_uuid
            && [$request['quantity'], $request['unit_cost'], $request['batch_number'], $request['expiry_date']] === ['500.000000', '18.457300', 'B-261125-01', '2028-11-24']);
        $this->actingAs($this->approver())->get(route('production.orders.show', $sent))->assertInertia(fn (Assert $page) => $page
            ->where('sanad.mode', 'lan')->where('sanad.delivery.status', 'confirmed')->where('sanad.sync.consume.total_cost', '2575.00'));
    }

    public function test_direct_mode_refused_for_short_stock_keeps_the_order_approved_with_the_shortfall_and_sends_nothing_more(): void
    {
        $order = $this->lanOrder();
        $shortfall = ['product_uuid' => $this->water->sanad_product_uuid, 'required' => '2.1625', 'available' => '1.0'];
        SanadFake::fail('consume', 422, 'الرصيد غير كافٍ', ['shortfalls' => [$shortfall]]);
        SanadFake::receive();

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($order), 'الرصيد غير كافٍ');

        $order->refresh();
        $this->assertSame(ProductionOrderStatus::Approved, $order->status);
        $this->assertNull($order->sent_at);
        $this->assertEquals(['consume', 422, [$shortfall]], [$order->sanad_sync['error']['step'], $order->sanad_sync['error']['status'], $order->sanad_sync['error']['shortfalls']]);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/receive'));
        $this->actingAs($this->approver())->get(route('production.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('sanad.delivery.status', 'failed')->where('can.send', true)->where('sanad.sync.error.shortfalls.0.available', '1.0'));
    }

    public function test_direct_mode_retry_after_a_refused_receipt_does_not_consume_again(): void
    {
        $order = $this->lanOrder();
        SanadFake::consume();
        SanadFake::fail('receive', 409, 'تعارض');

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($order));
        $this->assertSame(SanadDeliveryStatus::Partial, SanadDelivery::fromSync($order->fresh()->sanad_sync)->status);

        SanadFake::receive();
        $sent = $this->service->send($order->fresh());

        $this->assertSame(ProductionOrderStatus::Sent, $sent->status);
        $this->assertSame(2, $sent->sanad_sync['attempts']);
        $this->assertNull($sent->sanad_sync['error']);
        $this->assertSame(SanadDeliveryStatus::Confirmed, SanadDelivery::fromSync($sent->sanad_sync)->status);
        $consumes = Http::recorded(fn ($request) => str_ends_with($request->url(), '/consume'));
        $this->assertCount(1, $consumes);
    }

    public function test_direct_mode_sends_the_same_idempotency_key_for_the_same_body(): void
    {
        $order = $this->lanOrder();
        SanadFake::consume();
        SanadFake::fail('receive', 503, 'down');

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($order));
        SanadFake::receive();
        $this->service->send($order->fresh());

        $keys = Http::recorded(fn ($request) => str_ends_with($request->url(), '/receive'))->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0]);
        $this->assertGreaterThanOrEqual(2, $keys->count());
        $this->assertCount(1, $keys->unique());
    }

    public function test_with_no_device_and_no_lan_address_the_send_is_still_blocked(): void
    {
        $order = $this->approvedOrder();
        SanadDevice::query()->delete();
        config(['sanad.client.base_url' => null, 'sanad.client.token' => null]);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($order), __('production.blockers.no_device'));
        $this->assertNull($order->fresh()->sanad_sync);
    }

    public function test_the_operator_never_gets_the_sanad_state_or_its_cost(): void
    {
        $order = $this->lanOrder();
        SanadFake::consume();
        SanadFake::receive();
        $sent = $this->service->send($order);

        $props = $this->pageProps($this->actingAs(User::factory()->create(['role' => 'production']))->get(route('production.orders.show', $sent))->assertOk());

        $this->assertArrayNotHasKey('sanad', $props);
        $this->assertStringNotContainsString('2575.00', json_encode($props));
    }

    public function test_a_published_finished_good_shows_what_sanad_answered_in_its_history(): void
    {
        $this->productionProduct();
        $approval = FormulaCostApproval::query()->where('formula_id', $this->formula->id)->firstOrFail();
        $row = SanadOutbox::factory()->create(['device_id' => $this->device->id, 'kind' => SanadOutboxKind::ProductUpsert, 'external_id' => $this->formula->sanad_product_uuid, 'status' => SanadOutboxStatus::Failed, 'failures' => 1]);
        $retry = SanadOutbox::factory()->create(['device_id' => $this->device->id, 'kind' => SanadOutboxKind::ProductUpsert, 'external_id' => $this->formula->sanad_product_uuid, 'status' => SanadOutboxStatus::Applied, 'failures' => 1, 'retry_of' => $row->id]);
        $publication = FormulaPublication::factory()->create(['cost_approval_id' => $approval->id, 'mode' => 'outbox', 'outbox_id' => $row->id, 'sanad_product_uuid' => $this->formula->sanad_product_uuid]);

        $panel = app(FinishedGoodPublishService::class)->panel($this->formula, null);

        $this->assertSame([$publication->id, 'applied', $retry->id], [$panel['history'][0]['id'], $panel['history'][0]['delivery']['status'], $panel['history'][0]['delivery']['outbox_id']]);
    }

    public function test_a_direct_publication_counts_as_applied(): void
    {
        $this->productionProduct();
        $approval = FormulaCostApproval::query()->where('formula_id', $this->formula->id)->firstOrFail();
        $publication = FormulaPublication::factory()->create(['cost_approval_id' => $approval->id, 'mode' => 'lan', 'outbox_id' => null]);

        $panel = app(FinishedGoodPublishService::class)->panel($this->formula, null);

        $this->assertSame([$publication->id, 'applied'], [$panel['history'][0]['id'], $panel['history'][0]['delivery']['status']]);
    }
}
