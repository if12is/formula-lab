<?php

namespace Tests\Feature\Costing;

use App\Models\Formula;
use App\Models\Price;
use App\Models\SanadPurchaseLine;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Costing\CostReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\TestCase;

/** I-08: every number of the cost report comes from the engine and says where it came from. A60 % x 10 + B 40 % x 25 = 6 + 10 = 16. */
class CostReportTest extends TestCase
{
    use BuildsCostedFormulas, RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formula = $this->costedProduct(Tenant::factory()->create()->id);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(CostReportService::class)->report($this->formula, $this->formula->versions()->firstOrFail());
    }

    public function test_the_total_and_each_line_are_the_engines_numbers_with_their_origin(): void
    {
        $report = $this->report();

        $this->assertTrue($report['ok']);
        $this->assertSame('16', $report['total']['value']);
        [$a, $b] = $report['lines'];
        $this->assertSame(['Material A', '60', '10', '6', 'invoice', 'INV-2026-0042', 'L1_coa_batch'], [$a['material'], $a['percent_ww'], $a['price_egp_per_kg'], $a['contribution_egp_per_kg'], $a['quote']['origin'], $a['quote']['source_title'], $a['quote']['confidence']]);
        $this->assertSame(['Material B', '40', '25', '10', 'manual', 'Phone quote'], [$b['material'], $b['percent_ww'], $b['price_egp_per_kg'], $b['contribution_egp_per_kg'], $b['quote']['origin'], $b['quote']['source_title']]);
        $this->assertSame('2026-11-01', $a['quote']['quoted_on']);
        $this->assertCount(2, $report['total']['trace']['inputs'], 'the explain tree has one branch per line');
    }

    public function test_a_lower_confidence_quote_is_listed_as_not_used_and_why(): void
    {
        Price::factory()->create(['trade_material_id' => $this->materialA->id, 'amount' => '9', 'confidence' => 'L5_reference', 'quoted_on' => '2026-11-10', 'source_id' => Source::factory()->create()->id]);

        $line = $this->report()['lines'][0];

        $this->assertSame('10', $line['price_egp_per_kg'], 'the better confidence wins even though the other is newer');
        $this->assertSame('lower confidence', $line['rejected'][0]['reason']);
        $this->assertSame('9', $line['rejected'][0]['quote']['amount']);
    }

    public function test_a_material_without_a_price_is_a_named_gap_never_zero(): void
    {
        Price::query()->where('trade_material_id', $this->materialB->id)->delete();

        $report = $this->report();

        $this->assertFalse($report['ok']);
        $this->assertNull($report['total']);
        $this->assertNull($report['lines'][1]['contribution_egp_per_kg']);
        $this->assertSame('Material B', $report['missing'][0]['material']);
        $this->assertSame(__('engine.cost.price_missing'), $report['missing'][0]['reason']);
    }

    public function test_sanad_purchase_invoices_of_a_linked_material_are_shown_next_to_its_price(): void
    {
        $uuid = fake()->uuid();
        $this->materialA->update(['sanad_product_uuid' => $uuid]);
        SanadPurchaseLine::factory()->create(['sanad_product_uuid' => $uuid, 'purchase_date' => '2026-11-15', 'unit_cost' => '10.00', 'quantity' => '50']);

        $invoices = $this->report()['lines'][0]['invoices'];

        $this->assertCount(1, $invoices);
        $this->assertSame(['2026-11-15', '10', '50'], [$invoices[0]['purchase_date'], $invoices[0]['unit_cost'], $invoices[0]['quantity']]);
        $this->assertSame([], $this->report()['lines'][1]['invoices']);
    }

    public function test_the_product_unit_cost_carries_its_source_and_origin(): void
    {
        $unit = $this->report()['unit_cost'];

        $this->assertSame(['18.4573', 'Cost sheet 2026-11', 'manual', 'L2_supplier_tds'], [$unit['value'], $unit['source_title'], $unit['origin'], $unit['confidence']]);
    }

    public function test_the_report_page_renders_with_the_engine_hashes_for_the_latest_approved_version(): void
    {
        $this->get(route('formulas.cost-report', $this->formula))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/CostReport')
            ->where('report.total.value', '16')
            ->where('report.engine.input_hash', fn (string $hash) => strlen($hash) === 64)
            ->where('canApprove', true));
    }

    public function test_an_unapproved_version_cannot_be_approved_from_the_page(): void
    {
        $draft = $this->costedProduct(Tenant::factory()->create()->id, ['code' => 'DRAFT-1'], approved: false);

        $this->get(route('formulas.cost-report', $draft))->assertInertia(fn (Assert $page) => $page->where('canApprove', false)->where('report.version.status', 'draft'));
    }

    public function test_every_material_a_report_names_is_a_real_material(): void
    {
        $this->assertSame(2, TradeMaterial::query()->whereIn('id', array_column($this->report()['lines'], 'material_id'))->count());
    }
}
