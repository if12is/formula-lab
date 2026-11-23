<?php

namespace Tests\Feature\Production;

use App\Enums\FormulaStatus;
use App\Enums\ItemKind;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\ProductionException;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Formula\FormulaVersionService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** Q-01: a production order is an approved version and a batch size, with a snapshot and its hash; Q-02 sheet; the message for Sanad. */
class ProductionOrderTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private ProductionOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionOrderService::class);
    }

    private function order(array $overrides = []): ProductionOrder
    {
        $version = $this->productionProduct();

        return $this->service->create($version, $this->orderData($overrides));
    }

    private function approver(): User
    {
        config(['production.approver_roles' => ['quality_manager']]);

        return User::factory()->create(['role' => 'quality_manager']);
    }

    public function test_an_order_snapshots_the_approved_version_with_its_hash(): void
    {
        $order = $this->order();

        $this->assertSame(['PO-2026-0001', 'B-261125-01', ProductionOrderStatus::Draft], [$order->code, $order->batch_number, $order->status]);
        $this->assertSame($this->version->hash, $order->formula_hash);
        $this->assertSame($this->version->hash, $order->snapshot['formula']['hash']);
        $this->assertSame(['Surfactant X', 'Salt', 'Water'], array_column($order->snapshot['lines'], 'material'));
        $this->assertSame('12', $order->snapshot['lines'][0]['percent_ww']);
        $this->assertNull($order->snapshot['lines'][2]['percent_ww'], 'The q.s. line has no percentage of its own.');
        $this->assertCount(3, $order->snapshot['steps']);
        $this->assertSame('1', $order->snapshot['steps'][1]['line_no']);
        $this->assertSame('2.5', $order->batch_size);
    }

    public function test_the_snapshot_does_not_move_when_the_material_is_renamed_afterwards(): void
    {
        $order = $this->order();
        $this->water->update(['trade_name' => 'Renamed water']);

        $this->assertSame('Water', $order->fresh()->snapshot['lines'][2]['material']);
    }

    public function test_running_numbers_count_orders_of_the_year_and_batches_of_the_day(): void
    {
        $first = $this->order();
        $second = $this->service->create($this->version, $this->orderData());
        $other = $this->service->create($this->version, $this->orderData(['planned_on' => '2026-11-26']));

        $this->assertSame(['PO-2026-0001', 'PO-2026-0002', 'PO-2026-0003'], [$first->code, $second->code, $other->code]);
        $this->assertSame(['B-261125-01', 'B-261125-02', 'B-261126-01'], [$first->batch_number, $second->batch_number, $other->batch_number]);
    }

    public function test_only_an_approved_version_whose_hash_still_matches_makes_an_order(): void
    {
        $draft = app(FormulaVersionService::class)->createVersion(Formula::factory()->create(), [['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '100']], null);
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->create($draft, $this->orderData()), (string) $draft->version);

        $tampered = FormulaVersion::factory()->create();
        FormulaLine::factory()->create(['formula_version_id' => $tampered->id, 'percent_ww' => '100']);
        $tampered->update(['status' => FormulaStatus::Approved]);
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->create($tampered, $this->orderData()), __('exceptions.production_version_hash_mismatch', ['formula' => $tampered->formula->code, 'version' => 1]));
        $this->assertSame(0, ProductionOrder::query()->count());
    }

    public function test_a_formula_the_engine_cannot_weigh_is_refused_with_its_reason(): void
    {
        $formula = Formula::factory()->create();
        $a = TradeMaterial::factory()->create(['substance_id' => null]);
        $b = TradeMaterial::factory()->create(['substance_id' => null]);
        $version = app(FormulaVersionService::class)->createVersion($formula, [['trade_material_id' => $a->id, 'percent_ww' => '60'], ['trade_material_id' => $b->id, 'percent_ww' => '30']], null);
        $version->update(['status' => FormulaStatus::Approved]);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->create($version->fresh(), $this->orderData()), __('engine.formula.sum_not_100'));
    }

    public function test_the_form_refuses_a_volume_batch_a_resolution_not_on_the_list_and_raw_material_as_packaging(): void
    {
        $version = $this->productionProduct();
        $valid = [...$this->orderData(), 'formula_version_id' => $version->id];

        $this->post(route('production.orders.store'), [...$valid, 'batch_unit' => 'L'])->assertSessionHasErrors('batch_unit');
        $this->post(route('production.orders.store'), [...$valid, 'scale_resolution_g' => '0.123'])->assertSessionHasErrors('scale_resolution_g');
        $this->post(route('production.orders.store'), [...$valid, 'batch_size' => '0'])->assertSessionHasErrors('batch_size');
        $this->post(route('production.orders.store'), [...$valid, 'packaging' => [['trade_material_id' => $this->water->id, 'quantity' => '5']]])->assertSessionHasErrors('packaging.0.trade_material_id');
        $this->post(route('production.orders.store'), [...$valid, 'expiry_date' => '2026-01-01'])->assertSessionHasErrors('expiry_date');
        $this->assertSame(0, ProductionOrder::query()->count());
    }

    public function test_the_form_creates_an_order_with_its_packaging_and_redirects_to_it(): void
    {
        $version = $this->productionProduct();
        $bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Bottle 400']);

        $response = $this->post(route('production.orders.store'), [...$this->orderData(), 'formula_version_id' => $version->id, 'packaging' => [['trade_material_id' => $bottle->id, 'quantity' => '505']]]);

        $order = ProductionOrder::query()->firstOrFail();
        $response->assertRedirect(route('production.orders.show', $order));
        $this->assertEquals([['trade_material_id' => $bottle->id, 'material' => 'Bottle 400', 'quantity' => '505']], $order->snapshot['packaging']);
    }

    public function test_the_version_search_lists_approved_versions_only(): void
    {
        $this->productionProduct();
        $draft = Formula::factory()->create(['code' => 'DRAFT-ONLY']);
        app(FormulaVersionService::class)->createVersion($draft, [['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '100']], null);

        $labels = collect($this->getJson(route('production.versions.search', ['q' => '']))->assertOk()->json())->pluck('label')->implode('|');
        $this->assertStringContainsString($this->formula->code, $labels);
        $this->assertStringNotContainsString('DRAFT-ONLY', $labels);
    }

    public function test_only_a_signed_in_user_with_an_approver_role_approves_and_the_order_then_stops_changing(): void
    {
        $order = $this->order();

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->approve($order, null));
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->approve($order, User::factory()->create(['role' => 'production'])));

        $approver = $this->approver();
        $this->actingAs($approver)->post(route('production.orders.approve', $order))->assertSessionHas('success');
        $order->refresh();
        $this->assertSame([ProductionOrderStatus::Approved, $approver->id], [$order->status, $order->approved_by]);

        // Approved: the batch and the snapshot cannot change; only the sending moves.
        $this->assertDatabaseRefuses(fn () => DB::table('production_orders')->where('id', $order->id)->update(['batch_size' => '99']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_orders')->where('id', $order->id)->update(['snapshot' => '{}']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_orders')->where('id', $order->id)->delete());
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->approve($order, $approver));
    }

    public function test_a_draft_order_can_be_deleted_and_an_approved_one_cannot(): void
    {
        $order = $this->order();
        $this->assertSame(1, DB::table('production_orders')->where('id', $order->id)->delete());
    }

    public function test_the_weighing_sheet_lists_weights_in_the_order_of_the_steps_and_holds_no_price(): void
    {
        $order = $this->order();

        $response = $this->get(route('production.orders.weighing', $order))->assertOk();
        $response->assertInertia(function (Assert $page): void {
            $sheet = $page->toArray()['props']['sheet'];
            $this->assertTrue($sheet['ok']);
            $this->assertSame(
                [['Water', '2162', '2162.5'], ['Surfactant X', '300', '300'], ['Salt', '38', '37.5']],
                array_map(fn (array $line): array => [$line['material'], $line['weighG'], $line['exactG']], $sheet['sheet']['lines']),
            );
            $this->assertSame(['2500', '2500'], [$sheet['sheet']['totalWeighG'], $sheet['sheet']['totalExactG']]);
            $this->assertSame([1, 2, 3], array_column($sheet['sheet']['lines'], 'order'));
            $this->assertSame(64, strlen($sheet['sheet']['sheetHash']));
        });
        $data = json_encode($this->pageProps($response));
        $this->assertStringNotContainsString('18.4573', $data, 'No price on the operator sheet.');
        $this->assertStringNotContainsString('unit_cost', $data);
        $this->assertStringNotContainsString('cost', $data);
    }

    public function test_the_sheet_rounds_to_the_resolution_of_the_order(): void
    {
        $order = $this->order(['scale_resolution_g' => '0.1']);

        $this->get(route('production.orders.weighing', $order))->assertInertia(function (Assert $page): void {
            $lines = $page->toArray()['props']['sheet']['sheet']['lines'];
            $this->assertSame(['2162.5', '300.0', '37.5'], array_column($lines, 'weighG'));
        });
    }

    public function test_sending_an_order_that_is_not_ready_names_every_blocker_and_sends_nothing(): void
    {
        $version = $this->productionProduct(linkToSanad: false, approveCost: false);
        $order = $this->service->create($version, $this->orderData(['units_count' => null]));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        SanadDevice::query()->delete();

        $exception = null;
        try {
            $this->service->send($order);
        } catch (ProductionException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        foreach ([
            __('production.blockers.no_device'),
            __('production.blockers.formula_not_published'),
            __('production.blockers.units_missing'),
            __('production.blockers.cost_not_approved'),
            __('production.blockers.material_not_linked', ['material' => 'Surfactant X']),
            __('production.blockers.material_not_linked', ['material' => 'Water']),
        ] as $blocker) {
            $this->assertStringContainsString($blocker, $exception->getMessage());
        }
        $this->assertSame(0, SanadOutbox::query()->count());
        $this->assertSame(ProductionOrderStatus::Approved, $order->fresh()->status);
    }

    public function test_a_draft_order_cannot_be_sent(): void
    {
        $order = $this->order();

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($order), $order->code);
    }

    public function test_sending_queues_the_production_order_for_sanad_with_six_decimal_quantities_and_the_approved_unit_cost(): void
    {
        $order = $this->order();
        $bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Bottle', 'sanad_product_uuid' => (string) Str::uuid()]);
        $order = $this->service->create($this->version, $this->orderData(['packaging' => [['trade_material_id' => $bottle->id, 'quantity' => '505']]]));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        $sent = $this->service->send($order);

        $row = SanadOutbox::query()->findOrFail($sent->sanad_outbox_id);
        $this->assertSame([SanadOutboxKind::ProductionOrder, $order->code], [$row->kind, $row->external_id]);
        $this->assertSame(ProductionOrderStatus::Sent, $sent->status);
        $payload = $row->payload;
        $this->assertSame($this->formula->sanad_product_uuid, $payload['finished_good_uuid']);
        $this->assertSame(['B-261125-02', '500.000000', 'unit', '2026-11-25', '2028-11-24'], [$payload['batch_number'], $payload['quantity'], $payload['uom'], $payload['production_date'], $payload['expiry_date']]);
        $this->assertSame(['500.000000', 'unit', '18.457300'], [$payload['receive']['quantity'], $payload['receive']['uom'], $payload['receive']['unit_cost']]);
        $this->assertSame($this->version->hash, $payload['formula_version']['hash']);
        // The planned masses in kg, exact (not the rounded weights), in the order of the sheet; then the packaging in units.
        $this->assertSame(
            [[$this->water->sanad_product_uuid, '2.162500', 'kg', 'raw_material'], [$this->surfactant->sanad_product_uuid, '0.300000', 'kg', 'raw_material'], [$this->salt->sanad_product_uuid, '0.037500', 'kg', 'raw_material'], [$bottle->sanad_product_uuid, '505.000000', 'unit', 'packaging']],
            array_map(fn (array $line): array => [$line['product_uuid'], $line['quantity'], $line['uom'], $line['kind']], $payload['consume']),
        );

        // Closed once sent, and not sent twice.
        $this->assertDatabaseRefuses(fn () => DB::table('production_orders')->where('id', $order->id)->update(['notes' => 'late']));
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->send($sent));
        $this->assertSame(1, SanadOutbox::query()->where('kind', SanadOutboxKind::ProductionOrder)->count());
    }

    public function test_sanad_pulls_the_order_through_the_outbox_and_the_ack_shows_on_the_order(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $this->device->forceFill(['token_hash' => SanadDevice::hashToken('fls_prod-test'), 'abilities' => ['outbox:read', 'outbox:ack']])->save();
        $sent = $this->service->send($order);
        $headers = ['Authorization' => 'Bearer fls_prod-test', 'Accept' => 'application/json'];

        $pulled = $this->getJson('/api/sanad/v1/outbox', $headers)->assertOk()->json('data.0');
        $this->assertSame(['production_order', $order->code], [$pulled['type'], $pulled['external_id']]);

        $this->postJson('/api/sanad/v1/outbox/ack', ['device_uuid' => (string) Str::uuid(), 'acks' => [['id' => $pulled['id'], 'status' => 'applied', 'result' => ['consume_status' => 'consumed', 'receive_status' => 'received', 'product_batch_uuid' => 'pb-1']]]], [...$headers, 'Idempotency-Key' => 'ack-1'])->assertOk();

        $this->actingAs($this->approver())->get(route('production.orders.show', $sent))->assertInertia(fn (Assert $page) => $page
            ->where('sanad.outbox.status', 'applied')->where('sanad.outbox.result.consume_status', 'consumed')->where('sanad.outbox.result.product_batch_uuid', 'pb-1'));
    }

    public function test_an_operator_role_gets_no_cost_and_no_sanad_message_in_the_page_data(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $props = fn (User $user): array => $this->pageProps($this->actingAs($user)->get(route('production.orders.show', $order))->assertOk());

        $manager = $props($this->approver());
        $this->assertSame('18.4573', $manager['cost']['unit_cost']);
        $this->assertArrayHasKey('sanad', $manager);
        $this->assertTrue($manager['audience']['sees_prices']);

        $operator = $props(User::factory()->create(['role' => 'production']));
        $this->assertArrayNotHasKey('cost', $operator);
        $this->assertArrayNotHasKey('sanad', $operator);
        $this->assertStringNotContainsString('18.4573', json_encode($operator));
        $this->assertFalse($operator['audience']['sees_prices']);
        $this->assertSame($order->code, $operator['order']['code']);
        $this->assertTrue($operator['sheet']['ok'], 'The operator still has the sheet.');
    }

    public function test_the_list_filters_by_status_and_searches_by_code_batch_or_formula(): void
    {
        $first = $this->order();
        $second = $this->service->create($this->version, $this->orderData(['planned_on' => '2026-12-01']));
        $second->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        $codes = fn (array $query): array => array_column($this->get(route('production.orders.index', $query))->assertOk()->original->getData()['page']['props']['list']['rows'], 'code');

        $this->assertEqualsCanonicalizing([$first->code, $second->code], $codes([]));
        $this->assertSame([$second->code], $codes(['status' => 'approved']));
        $this->assertSame([$first->code], $codes(['search' => $first->batch_number]));
        $this->assertSame([$second->code], $codes(['search' => $this->formula->code, 'planned_on_from' => '2026-11-30']));
        $this->assertSame([], $codes(['search' => 'nothing-like-this']));
        $this->assertSame([$second->code, $first->code], $codes(['sort' => 'planned', 'direction' => 'desc']));
    }
}
