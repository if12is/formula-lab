<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportKind;
use App\Enums\FormulaStatus;
use App\Enums\PrintWatermark;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\ExportLog;
use App\Models\FormulaVersion;
use App\Models\User;
use App\Services\Exports\PrintDocumentService;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\ProductionTraceService;
use App\Support\Clock\Clock;
use App\Support\Clock\FixedClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** U-03: every print is written to the export log with who, what, when and the hash printed on it; the browser cannot choose the hash. */
class ExportLogTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FixedClock(CarbonImmutable::parse('2026-11-23T10:15:00Z')));
    }

    public function test_a_label_print_is_logged_with_the_version_hash_and_the_clock_time(): void
    {
        $version = $this->productionProduct();

        $response = $this->postJson(route('exports.store'), ['kind' => 'formula_label', 'subject_id' => $version->id, 'hash' => str_repeat('0', 64)])->assertCreated();

        $log = ExportLog::query()->sole();
        $this->assertSame(ExportKind::FormulaLabel, $log->kind);
        $this->assertSame($version->hash, $log->content_hash, 'the hash is the stored version hash, never the one the browser sent');
        $this->assertSame($this->formula->code.' v1', $log->subject_label);
        $this->assertSame(PrintWatermark::Approved, $log->watermark);
        $this->assertSame($version->id, $log->formula_version_id);
        $this->assertSame($this->formula->id, $log->formula_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame('2026-11-23T10:15:00+00:00', $log->exported_at->toIso8601String());
        $this->assertNull($log->user_id);
        $response->assertJsonPath('id', $log->id)->assertJsonPath('reference', strtoupper(substr($log->id, 0, 8)))->assertJsonPath('exported_at', '2026-11-23T10:15:00+00:00');
    }

    public function test_the_cost_report_of_a_version_that_is_not_approved_is_stamped_as_unapproved_and_the_user_is_recorded(): void
    {
        $version = $this->productionProduct(approveCost: false, withSteps: false);
        // An approved version is immutable (a trigger), so the draft is a second version of the same formula, never the approved one turned back.
        $version = FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 2, 'status' => FormulaStatus::Draft, 'reason' => 'Retuned']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('exports.store'), ['kind' => 'cost_report', 'subject_id' => $version->id])->assertCreated();

        $log = ExportLog::query()->sole();
        $this->assertSame(PrintWatermark::Unapproved, $log->watermark);
        $this->assertSame($user->getKey(), $log->user_id);
    }

    public function test_a_weighing_sheet_is_logged_with_the_hash_of_the_approved_version_it_came_from(): void
    {
        $version = $this->productionProduct();
        $order = app(ProductionOrderService::class)->create($version, $this->orderData());

        $this->postJson(route('exports.store'), ['kind' => 'weighing_sheet', 'subject_id' => $order->id])->assertCreated();

        $log = ExportLog::query()->sole();
        $this->assertSame($version->hash, $log->content_hash);
        $this->assertSame($order->formula_hash, $log->content_hash);
        $this->assertSame($order->id, $log->subject_id);
        $this->assertStringStartsWith($order->code, $log->subject_label);
        $this->assertSame($version->id, $log->formula_version_id);
    }

    public function test_a_lot_trace_is_logged_with_a_hash_of_what_it_shows(): void
    {
        $this->productionProduct();

        $this->postJson(route('exports.store'), ['kind' => 'lot_trace', 'lot' => 'L-7731'])->assertCreated();

        $log = ExportLog::query()->sole();
        $this->assertSame(PrintWatermark::Neutral, $log->watermark);
        $this->assertSame('L-7731', $log->subject_label);
        $this->assertSame(PrintDocumentService::traceHash('L-7731', null, app(ProductionTraceService::class)->byLot('L-7731')), $log->content_hash);
        $this->assertNull($log->formula_version_id);
        $this->assertNotNull($log->tenant_id);
    }

    public function test_bad_requests_are_refused_and_nothing_is_logged(): void
    {
        $version = $this->productionProduct();

        $this->postJson(route('exports.store'), ['kind' => 'nope', 'subject_id' => $version->id])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson(route('exports.store'), ['kind' => 'formula_label'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->postJson(route('exports.store'), ['kind' => 'lot_trace'])->assertUnprocessable()->assertJsonValidationErrors('lot');
        $this->postJson(route('exports.store'), ['kind' => 'formula_label', 'subject_id' => 'not-a-uuid'])->assertUnprocessable();
        $this->postJson(route('exports.store'), ['kind' => 'weighing_sheet', 'subject_id' => (string) Str::uuid()])->assertNotFound();

        $this->assertSame(0, ExportLog::query()->count());
    }

    public function test_a_log_row_cannot_be_changed_or_deleted(): void
    {
        $log = ExportLog::factory()->create();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $log->update(['content_hash' => 'x']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $log->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('export_logs')->update(['content_hash' => 'x']));
    }

    public function test_the_printable_pages_carry_the_document_the_preview_shows(): void
    {
        $version = $this->productionProduct();
        $order = app(ProductionOrderService::class)->create($version, $this->orderData());

        $this->get(route('formulas.versions.label', [$this->formula, $version->version]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.kind', 'formula_label')->where('document.hash', $version->hash)->where('document.watermark', 'approved')->where('document.subject_id', $version->id)->where('document.log_url', '/exports'));
        $this->get(route('formulas.cost-report', [$this->formula, 'version' => $version->version]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.kind', 'cost_report')->where('document.hash', $version->hash));
        $this->get(route('production.orders.weighing', $order))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.kind', 'weighing_sheet')->where('document.hash', $order->formula_hash)->where('document.subject_id', $order->id));
        $this->get(route('production.trace', ['lot' => 'L-1']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('document.kind', 'lot_trace')->where('document.lot', 'L-1')->where('document.watermark', 'neutral'));
        $this->get(route('production.trace'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('document', null));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function labels(array $query = []): array
    {
        $labels = [];
        $this->get(route('exports.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$labels): void {
            $labels = array_column($page->toArray()['props']['list']['rows'], 'subject_label');
        });

        return $labels;
    }

    public function test_the_list_filters_searches_sorts_and_shows_who(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Quality']);
        ExportLog::factory()->create(['subject_label' => 'A-1 v1', 'kind' => ExportKind::FormulaLabel, 'user_id' => $alice->id, 'content_hash' => str_repeat('a', 64), 'exported_at' => '2026-11-20 08:00:00']);
        ExportLog::factory()->create(['subject_label' => 'B-2 v1', 'kind' => ExportKind::CostReport, 'content_hash' => str_repeat('b', 64), 'watermark' => PrintWatermark::Unapproved, 'exported_at' => '2026-11-21 08:00:00']);
        ExportLog::factory()->create(['subject_label' => 'L-9', 'kind' => ExportKind::LotTrace, 'content_hash' => str_repeat('c', 64), 'watermark' => PrintWatermark::Neutral, 'exported_at' => '2026-11-22 08:00:00']);

        $this->assertSame(['L-9', 'B-2 v1', 'A-1 v1'], $this->labels(), 'newest first');
        $this->assertSame(['A-1 v1', 'B-2 v1', 'L-9'], $this->labels(['sort' => 'when', 'dir' => 'asc']));
        $this->assertSame(['B-2 v1'], $this->labels(['kind' => 'cost_report']));
        $this->assertSame(['B-2 v1'], $this->labels(['watermark' => 'unapproved']));
        $this->assertSame(['A-1 v1'], $this->labels(['search' => 'alice']), 'search finds the person');
        $this->assertSame(['L-9'], $this->labels(['search' => str_repeat('c', 12)]), 'search finds the hash');
        $this->assertSame(['B-2 v1', 'A-1 v1'], $this->labels(['exported_at_from' => '2026-11-20', 'exported_at_to' => '2026-11-21']));
        $this->assertSame([], $this->labels(['search' => 'nothing like this']));
        $this->assertSame(['A-1 v1', 'B-2 v1', 'L-9'], $this->labels(['sort' => 'subject', 'dir' => 'asc']));

        $this->get(route('exports.index', ['search' => 'alice']))->assertInertia(fn (Assert $page) => $page
            ->where('list.rows.0.user_name', 'Alice Quality')->where('list.rows.0.content_hash', str_repeat('a', 64))->where('list.meta.total', 1)->has('list.schema.filters', 4));
    }

    public function test_a_row_links_back_to_the_printed_document(): void
    {
        $version = $this->productionProduct();
        $order = app(ProductionOrderService::class)->create($version, $this->orderData());
        $this->postJson(route('exports.store'), ['kind' => 'formula_label', 'subject_id' => $version->id]);
        $this->postJson(route('exports.store'), ['kind' => 'cost_report', 'subject_id' => $version->id]);
        $this->postJson(route('exports.store'), ['kind' => 'weighing_sheet', 'subject_id' => $order->id]);
        $this->postJson(route('exports.store'), ['kind' => 'lot_trace', 'lot' => 'L-5']);

        $this->get(route('exports.index', ['sort' => 'kind', 'dir' => 'asc']))->assertInertia(fn (Assert $page) => $page
            ->where('list.rows.0.href', route('formulas.cost-report', ['formula' => $this->formula->id, 'version' => 1], false))
            ->where('list.rows.1.href', route('formulas.versions.label', [$this->formula->id, 1], false))
            ->where('list.rows.2.href', route('production.trace', ['lot' => 'L-5'], false))
            ->where('list.rows.3.href', route('production.orders.weighing', $order->id, false)));
    }
}
