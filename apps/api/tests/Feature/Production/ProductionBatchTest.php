<?php

namespace Tests\Feature\Production;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\ItemKind;
use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationVerdict;
use App\Enums\Production\ProductionBatchStatus;
use App\Enums\Production\ProductionOrderStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchCheck;
use App\Models\ProductionBatchLine;
use App\Models\ProductionDeviation;
use App\Models\ProductionOrder;
use App\Models\SanadPurchaseLine;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Formula\FormulaProcessService;
use App\Services\Formula\FormulaVersionService;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** Q-11: a batch is a snapshot of the approved version; weights, lots, deviations with their approval, and the closing rules. */
class ProductionBatchTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private ProductionBatchService $service;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionBatchService::class);
    }

    /** An approved order of the fixture product (2.5 kg at 1 g) and its started batch. */
    private function batch(array $orderOverrides = [], ?array $packaging = null): ProductionBatch
    {
        $version = $this->productionProduct();
        $this->order = app(ProductionOrderService::class)->create($version, $this->orderData([...$orderOverrides, ...($packaging === null ? [] : ['packaging' => $packaging])]));
        $this->order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        return $this->service->start($this->order, 'Operator A', '2');
    }

    private function approver(): User
    {
        config(['production.approver_roles' => ['quality_manager']]);

        return User::factory()->create(['role' => 'quality_manager']);
    }

    private function line(ProductionBatch $batch, string $name): ProductionBatchLine
    {
        return $batch->lines()->get()->first(fn (ProductionBatchLine $line): bool => $line->tradeMaterial->trade_name === $name);
    }

    private function weigh(ProductionBatch $batch, string $name, string $actual, array $extra = []): ProductionBatchLine
    {
        return $this->service->weigh($batch, $this->line($batch, $name), ['actual_quantity' => $actual, 'weighed_by' => 'Operator A', ...$extra]);
    }

    /** Every line weighed exactly as asked. */
    private function weighAll(ProductionBatch $batch): void
    {
        foreach ([['Water', '2162'], ['Surfactant X', '300'], ['Salt', '38']] as [$name, $actual]) {
            $this->weigh($batch, $name, $actual);
        }
    }

    public function test_starting_a_batch_copies_the_snapshot_and_hash_and_opens_the_lines_in_weighing_order(): void
    {
        $bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Bottle']);
        $batch = $this->batch(packaging: [['trade_material_id' => $bottle->id, 'quantity' => '505']]);

        $this->assertSame([$this->order->batch_number, $this->version->hash, ProductionBatchStatus::InProgress], [$batch->batch_number, $batch->formula_hash, $batch->status]);
        $this->assertEquals($this->order->snapshot, $batch->snapshot);
        $this->assertSame(
            [[1, 'Water', 'g', '2162.5', '2162'], [2, 'Surfactant X', 'g', '300', '300'], [3, 'Salt', 'g', '37.5', '38'], [4, 'Bottle', 'unit', '505', '505']],
            $batch->lines()->get()->map(fn (ProductionBatchLine $line): array => [$line->line_no, $line->tradeMaterial->trade_name, $line->uom, $line->planned_quantity, $line->weigh_quantity])->all(),
        );
        $this->assertSame('2', $batch->planned_waste_pct);
    }

    public function test_the_snapshot_of_the_batch_does_not_follow_later_changes_of_the_formula(): void
    {
        $batch = $this->batch();
        $this->salt->update(['trade_name' => 'Table salt']);

        $this->assertSame('Salt', $batch->fresh()->snapshot['lines'][1]['material']);
        $this->assertSame($this->version->hash, $batch->fresh()->formula_hash);
    }

    public function test_an_order_has_one_batch_and_only_an_approved_order_starts_one(): void
    {
        $batch = $this->batch();
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->start($this->order, 'Other', null), $this->order->code);

        $draft = app(ProductionOrderService::class)->create($this->version, $this->orderData());
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->start($draft, 'Operator', null), $draft->code);
        $this->assertSame(1, ProductionBatch::query()->count());
        $this->assertSame($this->order->id, $batch->production_order_id);
    }

    public function test_a_weight_records_the_lot_who_and_when_and_can_be_corrected_while_the_batch_is_open(): void
    {
        $batch = $this->batch();

        $line = $this->weigh($batch, 'Surfactant X', '301', ['lot_number' => ' L-7731 ']);
        $this->assertSame(['301', 'L-7731', 'Operator A'], [$line->actual_quantity, $line->lot_number, $line->weighed_by]);
        $this->assertNotNull($line->weighed_at);

        $this->assertSame('300', $this->weigh($batch, 'Surfactant X', '300')->actual_quantity);
        $this->assertNull($this->line($batch->fresh(), 'Surfactant X')->lot_number, 'A correction without a lot clears it.');
    }

    public function test_the_cost_of_a_lot_comes_from_its_sanad_purchase_line_by_the_engine_or_is_typed_or_stays_empty(): void
    {
        $batch = $this->batch();
        SanadPurchaseLine::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sanad_product_uuid' => $this->surfactant->sanad_product_uuid,
            'batch_number' => 'L-7731',
            'unit_cost' => '1287.50',
            'quantity' => '2.00',
            'base_quantity' => '50.00',
        ]);

        $fromInvoice = $this->weigh($batch, 'Surfactant X', '300', ['lot_number' => 'L-7731']);
        $this->assertSame(['51.5', 'kg', 'sanad_purchase'], [$fromInvoice->unit_cost, $fromInvoice->cost_uom, $fromInvoice->unit_cost_source]);
        $this->assertNotNull($fromInvoice->sanad_purchase_line_uuid);

        $typed = $this->weigh($batch, 'Surfactant X', '300', ['lot_number' => 'L-7731', 'unit_cost' => '49.25']);
        $this->assertSame(['49.25', 'manual', null], [$typed->unit_cost, $typed->unit_cost_source, $typed->sanad_purchase_line_uuid]);

        $unknownLot = $this->weigh($batch, 'Salt', '38', ['lot_number' => 'NOT-ON-FILE']);
        $this->assertNull($unknownLot->unit_cost, 'A lot with no invoice has no cost: never a guess.');
    }

    public function test_a_deviation_above_the_threshold_waits_for_approval_and_one_within_does_not(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();

        $within = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '304', 'unit' => 'g', 'reason' => 'Scale drift', 'recorded_by_name' => 'Operator A']);
        $this->assertSame([DeviationVerdict::Within, false, DeviationApprovalStatus::NotRequired, '5'], [$within->verdict, $within->requires_approval, $within->approval_status, $within->threshold_pct]);
        $this->assertSame('1.333333333333333333333333333333', substr($within->deviation_pct, 0, 32));

        $above = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Spilled and topped up', 'recorded_by_name' => 'Operator A']);
        $this->assertSame([DeviationVerdict::Exceeds, true, DeviationApprovalStatus::Pending, '10'], [$above->verdict, $above->requires_approval, $above->approval_status, $above->deviation_pct]);
    }

    public function test_with_no_threshold_every_non_zero_deviation_waits_and_says_the_threshold_is_not_set(): void
    {
        config(['production.deviation_threshold_pct' => null]);
        $batch = $this->batch();

        $small = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '300.1', 'unit' => 'g', 'reason' => 'Drift', 'recorded_by_name' => 'Operator A']);
        $this->assertSame([DeviationVerdict::ThresholdNotSet, true, DeviationApprovalStatus::Pending, null], [$small->verdict, $small->requires_approval, $small->approval_status, $small->threshold_pct]);

        $same = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '300', 'unit' => 'g', 'reason' => 'Rechecked', 'recorded_by_name' => 'Operator A']);
        $this->assertSame([DeviationVerdict::None, false], [$same->verdict, $same->requires_approval]);
    }

    public function test_a_deviation_with_no_numbers_is_not_evaluated_and_one_number_alone_is_refused(): void
    {
        $batch = $this->batch();

        $note = $this->service->deviate($batch, ['kind' => 'process', 'subject' => 'step 2', 'reason' => 'Mixed 5 minutes longer', 'recorded_by_name' => 'Operator A']);
        $this->assertSame([DeviationVerdict::NotEvaluated, false, null, null], [$note->verdict, $note->requires_approval, $note->planned, $note->deviation_pct]);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:1', 'planned' => '300', 'reason' => 'x', 'recorded_by_name' => 'A']));
    }

    public function test_the_deviation_form_needs_a_reason_and_a_name_and_a_known_kind(): void
    {
        $batch = $this->batch();
        $url = route('production.batches.deviations.store', $batch);
        $valid = ['kind' => 'other', 'subject' => 'note', 'reason' => 'Why', 'recorded_by_name' => 'Operator A'];

        $this->post($url, [...$valid, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, [...$valid, 'recorded_by_name' => ' '])->assertSessionHasErrors('recorded_by_name');
        $this->post($url, [...$valid, 'kind' => 'made-up'])->assertSessionHasErrors('kind');
        $this->post($url, [...$valid, 'planned' => '1e3', 'actual' => '2'])->assertSessionHasErrors('planned');
        $this->post($url, $valid)->assertSessionHasNoErrors();
        $this->assertSame(1, ProductionDeviation::query()->count());
    }

    public function test_only_an_approver_decides_a_pending_deviation_and_the_decision_is_final(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();
        $deviation = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Topped up', 'recorded_by_name' => 'Operator A']);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->decide($deviation, true, 'ok', null));
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->decide($deviation, true, 'ok', User::factory()->create(['role' => 'production'])));

        $approver = $this->approver();
        $decided = $this->service->decide($deviation, true, 'Checked the retained sample.', $approver);
        $this->assertSame([DeviationApprovalStatus::Approved, $approver->id, $approver->name, 'Checked the retained sample.'], [$decided->approval_status, $decided->decided_by, $decided->decided_by_name, $decided->decision_note]);
        $this->assertNotNull($decided->decided_at);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->decide($deviation, false, 'changed my mind', $approver));
        $this->assertDatabaseRefuses(fn () => DB::table('production_deviations')->where('id', $deviation->id)->update(['approval_status' => 'rejected']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_deviations')->where('id', $deviation->id)->delete());
    }

    public function test_a_recorded_deviation_cannot_be_rewritten_even_while_pending(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();
        $deviation = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Topped up', 'recorded_by_name' => 'Operator A']);

        $this->assertDatabaseRefuses(fn () => DB::table('production_deviations')->where('id', $deviation->id)->update(['reason' => 'a better story']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_deviations')->where('id', $deviation->id)->update(['actual' => '300']));
    }

    public function test_the_batch_cannot_close_until_everything_is_weighed_and_every_deviation_is_recorded_and_decided(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();

        $reasons = fn (): string => $this->captured(fn () => $this->service->complete($batch->fresh()));
        $this->assertStringContainsString(__('production.blockers.lines_not_weighed', ['count' => 3]), $reasons());

        $this->weigh($batch, 'Water', '2162');
        $this->weigh($batch, 'Salt', '38');
        $this->weigh($batch, 'Surfactant X', '330');
        $this->assertStringContainsString(__('production.blockers.deviation_missing', ['lines' => '2']), $reasons());

        $deviation = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Topped up', 'recorded_by_name' => 'Operator A']);
        $this->assertStringContainsString(__('production.blockers.deviations_pending', ['count' => 1]), $reasons());

        $this->service->decide($deviation, false, 'Not acceptable', $this->approver());
        $this->assertStringContainsString(__('production.blockers.deviations_rejected', ['count' => 1]), $reasons());
        $this->assertSame(ProductionBatchStatus::InProgress, $batch->fresh()->status);
    }

    public function test_an_approved_deviation_lets_the_batch_close_and_a_closed_batch_cannot_change(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();
        $this->weigh($batch, 'Water', '2162');
        $this->weigh($batch, 'Salt', '38');
        $this->weigh($batch, 'Surfactant X', '330', ['lot_number' => 'L-1']);
        $deviation = $this->service->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Topped up', 'recorded_by_name' => 'Operator A']);
        $this->service->decide($deviation, true, 'Accepted', $this->approver());

        $closed = $this->service->complete($batch->fresh());

        $this->assertSame(ProductionBatchStatus::Completed, $closed->status);
        $this->assertNotNull($closed->completed_at);
        $this->assertThrowsException(ProductionException::class, fn () => $this->weigh($batch->fresh(), 'Salt', '39'), $batch->batch_number);
        $this->assertThrowsException(ProductionException::class, fn () => $this->service->deviate($batch->fresh(), ['kind' => 'other', 'subject' => 'late', 'reason' => 'Late', 'recorded_by_name' => 'A']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_batch_lines')->where('production_batch_id', $batch->id)->update(['actual_quantity' => '1']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_batches')->where('id', $batch->id)->update(['operator' => 'Someone else']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_batches')->where('id', $batch->id)->delete());
    }

    public function test_a_batch_weighed_exactly_closes_with_no_deviation_at_all(): void
    {
        $batch = $this->batch();
        $this->weighAll($batch);

        $this->assertSame(ProductionBatchStatus::Completed, $this->service->complete($batch->fresh())->status);
        $this->assertSame(0, ProductionDeviation::query()->count());
    }

    public function test_a_reading_is_judged_against_the_limits_of_the_snapshot_and_a_missing_limit_is_unknown(): void
    {
        $batch = $this->batch();
        $unlimited = $this->service->check($batch, ['step_no' => 2, 'check_no' => 1, 'value' => '7', 'measured_by' => 'Operator A', 'measured_at' => '2026-11-25T10:00']);
        $this->assertSame(['ph', 'pH', 'UNKNOWN'], [$unlimited->property, $unlimited->unit, $unlimited->status]);

        $this->assertThrowsException(ProductionException::class, fn () => $this->service->check($batch, ['step_no' => 9, 'check_no' => 1, 'value' => '7', 'measured_by' => 'A', 'measured_at' => '2026-11-25T10:00']));
        $this->assertDatabaseRefuses(fn () => DB::table('production_batch_checks')->where('id', $unlimited->id)->update(['value' => '6']));
    }

    public function test_a_failed_check_blocks_the_batch_until_a_deviation_is_recorded_for_it(): void
    {
        $this->productionProduct();
        // A second approved version of the product whose first check has sourced limits 5 to 6.
        $source = Source::factory()->create();
        $draft = app(FormulaVersionService::class)->createVersion($this->formula, [['trade_material_id' => $this->water->id, 'percent_ww' => '100']], 'With limits');
        app(FormulaProcessService::class)->replace($draft, [['kind' => 'add', 'line_no' => '1', 'checks' => [['property' => 'ph', 'unit' => 'pH', 'limit_min' => '5', 'limit_max' => '6', 'source_id' => $source->id, 'confidence' => Confidence::L3Standard->value]]]]);
        $draft->refresh()->update(['status' => FormulaStatus::Approved]);
        $order = app(ProductionOrderService::class)->create($draft->refresh(), $this->orderData(['batch_size' => '1']));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $batch = $this->service->start($order, 'Operator A', null);
        $this->service->weigh($batch, $batch->lines()->firstOrFail(), ['actual_quantity' => '1000', 'weighed_by' => 'A']);

        $statuses = [];
        foreach (['5.5', '6', '6.1', '4.9'] as $value) {
            $statuses[$value] = $this->service->check($batch, ['step_no' => 1, 'check_no' => 1, 'value' => $value, 'measured_by' => 'Operator A', 'measured_at' => '2026-11-25T10:00'])->status;
        }
        $this->assertSame(['5.5' => 'PASS', '6' => 'PASS', '6.1' => 'FAIL', '4.9' => 'FAIL'], $statuses, 'A value on a limit passes; outside fails.');

        $this->assertStringContainsString(__('production.blockers.check_failed', ['checks' => '1/1']), $this->captured(fn () => $this->service->complete($batch->fresh())));

        $this->service->deviate($batch, ['kind' => 'check', 'subject' => 'step:1/check:1', 'reason' => 'pH corrected with citric acid', 'recorded_by_name' => 'Operator A']);
        $this->assertSame(ProductionBatchStatus::InProgress, $batch->fresh()->status);
        $this->service->check($batch, ['step_no' => 1, 'check_no' => 1, 'value' => '5.4', 'measured_by' => 'Operator A', 'measured_at' => '2026-11-25T11:00']);
        $this->assertSame(ProductionBatchStatus::Completed, $this->service->complete($batch->fresh())->status);
        $this->assertSame(5, ProductionBatchCheck::query()->count());
    }

    public function test_the_weigh_route_validates_the_numbers_and_refuses_a_line_of_another_batch(): void
    {
        $batch = $this->batch();
        $line = $this->line($batch, 'Salt');
        $url = route('production.batches.lines.weigh', [$batch, $line]);

        $this->put($url, ['actual_quantity' => '-1', 'weighed_by' => 'A'])->assertSessionHasErrors('actual_quantity');
        $this->put($url, ['actual_quantity' => '12,5', 'weighed_by' => 'A'])->assertSessionHasErrors('actual_quantity');
        $this->put($url, ['actual_quantity' => '38', 'weighed_by' => ''])->assertSessionHasErrors('weighed_by');
        $this->put($url, ['actual_quantity' => '38', 'weighed_by' => 'A', 'unit_cost' => 'free'])->assertSessionHasErrors('unit_cost');
        $this->put($url, ['actual_quantity' => '38', 'weighed_by' => 'A', 'lot_number' => 'L-9'])->assertSessionHasNoErrors();
        $this->assertSame('L-9', $line->fresh()->lot_number);

        $otherOrder = app(ProductionOrderService::class)->create($this->version, $this->orderData(['planned_on' => '2026-12-02']));
        $otherOrder->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $otherLine = $this->service->start($otherOrder, 'Other operator', null)->lines()->firstOrFail();
        $this->put(route('production.batches.lines.weigh', [$batch, $otherLine]), ['actual_quantity' => '1', 'weighed_by' => 'A'])->assertNotFound();
        $this->assertNull($otherLine->fresh()->actual_quantity);
    }

    public function test_the_batch_page_shows_the_engines_verdict_per_line_and_hides_cost_from_an_operator(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $batch = $this->batch();
        $this->weigh($batch, 'Water', '2162');
        $this->weigh($batch, 'Surfactant X', '330', ['unit_cost' => '49.25']);

        $props = $this->pageProps($this->actingAs($this->approver())->get(route('production.batches.show', $batch))->assertOk());
        $verdicts = collect($props['lines'])->mapWithKeys(fn (array $line): array => [$line['material'] => $line['deviation']['verdict'] ?? null]);
        $this->assertSame(['Water' => 'none', 'Surfactant X' => 'exceeds', 'Salt' => null], $verdicts->all());
        $this->assertSame('49.25', collect($props['lines'])->firstWhere('material', 'Surfactant X')['unit_cost']);
        $this->assertArrayHasKey('cost', $props);
        $this->assertNotEmpty($props['blockers']);

        $operator = $this->pageProps($this->actingAs(User::factory()->create(['role' => 'production']))->get(route('production.batches.show', $batch))->assertOk());
        $this->assertArrayNotHasKey('cost', $operator);
        $this->assertStringNotContainsString('49.25', json_encode($operator));
        $this->assertStringNotContainsString('unit_cost', json_encode($operator));
    }

    /** The message of the domain exception the callback throws (or fails the test when it does not throw). */
    private function captured(callable $callback): string
    {
        try {
            $callback();
        } catch (ProductionException $exception) {
            return $exception->getMessage();
        }
        $this->fail('The batch was expected to refuse.');
    }
}
