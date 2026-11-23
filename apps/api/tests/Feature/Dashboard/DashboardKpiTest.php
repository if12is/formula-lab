<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\RuleSetStatus;
use App\Enums\SourceKind;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaPublication;
use App\Models\FormulaVersion;
use App\Models\MaterialComposition;
use App\Models\Price;
use App\Models\ProductionOrder;
use App\Models\RuleSet;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Costing\CostApprovalService;
use App\Services\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

/**
 * The home page's decision figures against the real engine, all on the same hand case (TEST-FIXTURE numbers, today is 2026-11-23): material A 60 % at
 * 10 EGP/kg and B 40 % at 25 EGP/kg cost 16 EGP/kg and the cost is approved at that; A is later quoted 11, so today's cost is 16.6 (+0.6, +3.75 %).
 * The unit cost 20 against a published price of 25 is a 20 % margin; if the whole unit cost moved +3.75 % it would be 17 % (3 points less).
 */
class DashboardKpiTest extends TestCase
{
    use BuildsCostedFormulas, BuildsRules, RefreshDatabase;

    private string $tenantId;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->tenantId = app(EnsureDefaultTenantAction::class)()->getKey();
        $this->formula = $this->costedProduct($this->tenantId, ['code' => 'DASH-1', 'name' => 'Costed shampoo', 'product_type' => 'shampoo', 'unit_cost' => '20']);
    }

    private function approveCostAndRaiseA(bool $publish = true): void
    {
        $approval = app(CostApprovalService::class)->approve($this->formula, null, null);
        Price::factory()->create(['trade_material_id' => $this->materialA->id, 'amount' => '11', 'quoted_on' => '2026-11-10', 'source_id' => Source::factory()->create(['kind' => SourceKind::SupplierInvoice->value])->id, 'confidence' => Confidence::L1CoaBatch]);
        if ($publish) {
            FormulaPublication::factory()->create(['cost_approval_id' => $approval->id, 'details' => ['sell_price' => '25']]);
        }
    }

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        return app(DashboardService::class)->kpis();
    }

    public function test_it_shows_what_the_price_moves_since_approval_did_to_the_material_cost_and_the_most_they_can_do_to_the_margin(): void
    {
        $this->approveCostAndRaiseA();

        $impact = $this->kpis()['impact'];

        $this->assertSame('3.75', $impact['value']);
        $this->assertSame('DASH-1', $impact['largest']['code']);
        $row = $impact['items'][0];
        $this->assertSame(['16', '16.6', '0.6', '3.75'], [$row['impact']['approvedCostPerKg'], $row['impact']['currentCostPerKg'], $row['impact']['deltaPerKg'], $row['impact']['deltaPct']]);
        $this->assertSame(['20', '17', '-3'], [$row['margin']['marginNowPct'], $row['margin']['marginAtBoundPct'], $row['margin']['deltaPoints']]);
    }

    public function test_the_margin_effect_is_missing_not_zero_while_no_selling_price_was_published(): void
    {
        $this->approveCostAndRaiseA(publish: false);

        $row = $this->kpis()['impact']['items'][0];

        $this->assertNotNull($row['impact']);
        $this->assertNull($row['margin']);
        $this->assertSame('dashboard.sell_price_missing', $row['margin_missing'][0]['reason']);
    }

    public function test_without_an_approved_cost_there_is_no_impact_figure(): void
    {
        $impact = $this->kpis()['impact'];

        $this->assertNull($impact['value']);
        $this->assertSame([], $impact['items']);
    }

    public function test_stale_prices_count_against_the_owners_threshold_only(): void
    {
        config(['dashboard.stale_price_days' => '15']);

        $stale = $this->kpis()['stale'];

        // A was quoted 2026-11-01 (22 days), B 2026-11-02 (21 days): both are older than 15 days.
        $this->assertSame(['2', '2'], [$stale['value'], $stale['checked']]);
        $this->assertSame(['Material A', '22'], [$stale['items'][0]['material'], $stale['items'][0]['age_days']]);

        config(['dashboard.stale_price_days' => '21']);
        $this->assertSame('1', $this->kpis()['stale']['value']);

        config(['dashboard.stale_price_days' => '30']);
        $this->assertSame('0', $this->kpis()['stale']['value']);
    }

    public function test_with_no_threshold_decided_the_stale_figure_is_missing_with_its_reason_not_zero(): void
    {
        config(['dashboard.stale_price_days' => null]);

        $stale = $this->kpis()['stale'];

        $this->assertNull($stale['value']);
        $this->assertSame('dashboard.stale_threshold_not_set', $stale['missing'][0]['reason']);
        $this->assertSame([], $stale['items']);
    }

    public function test_a_material_with_no_price_is_listed_apart_and_not_called_fresh(): void
    {
        config(['dashboard.stale_price_days' => '30']);
        $unpriced = TradeMaterial::factory()->create(['trade_name' => 'Unpriced']);
        $version = FormulaVersion::factory()->create(['formula_id' => Formula::factory()->create(['tenant_id' => $this->tenantId])->id, 'version' => 1]);
        FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => 1, 'trade_material_id' => $unpriced->id, 'percent_ww' => '100']);

        $stale = $this->kpis()['stale'];

        $this->assertSame(['Unpriced'], $stale['unpriced']);
        $this->assertSame('2', $stale['checked']);
    }

    public function test_missing_prices_and_low_confidence_prices_are_flagged_per_formula(): void
    {
        $other = Formula::factory()->create(['tenant_id' => $this->tenantId, 'code' => 'DASH-2', 'name' => 'Gappy', 'product_type' => 'other']);
        $community = TradeMaterial::factory()->create(['trade_name' => 'Community priced']);
        $priceless = TradeMaterial::factory()->create(['trade_name' => 'Priceless']);
        Price::factory()->create(['trade_material_id' => $community->id, 'amount' => '5', 'source_id' => Source::factory()->create()->id, 'confidence' => Confidence::L6Community, 'quoted_on' => '2026-11-15']);
        $this->version($other, 1, [[$community, '50'], [$priceless, '50']], approved: false);

        $quality = $this->kpis()['quality'];

        $this->assertSame(['1', '2'], [$quality['value'], $quality['checked']]);
        $row = $quality['items'][0];
        $this->assertSame('DASH-2', $row['formula']['code']);
        $this->assertSame('Priceless', $row['missing'][0]['materialId']);
        $this->assertSame([['material' => 'Community priced', 'confidence' => 'L6_community']], $row['low']);
    }

    public function test_compliance_counts_fail_and_unknown_and_never_calls_an_unchecked_formula_a_pass(): void
    {
        $set = RuleSet::factory()->create(['code' => 'shampoo-set']);
        $this->addRule($set, $this->rule());
        $this->post("/compliance/{$set->getKey()}/publish")->assertSessionMissing('error');
        $this->assertSame(RuleSetStatus::Published, $set->refresh()->status);
        // 10 % TEST-0001 in material A, which is 60 % of DASH-1: 6 % against a limit of 0.5 %.
        MaterialComposition::factory()->create(['trade_material_id' => $this->materialA->id, 'cas' => 'TEST-0001', 'pct_ww' => '10']);
        // A second shampoo whose only material has no composition on file: cannot be decided.
        $undecided = Formula::factory()->create(['tenant_id' => $this->tenantId, 'code' => 'DASH-3', 'name' => 'No composition', 'product_type' => 'shampoo']);
        $this->version($undecided, 1, [[TradeMaterial::factory()->create(['trade_name' => 'Bare']), '100']], approved: false);
        // A formula of a type no published rule names.
        $unruled = Formula::factory()->create(['tenant_id' => $this->tenantId, 'code' => 'DASH-4', 'name' => 'Unruled', 'product_type' => 'soap']);
        $this->version($unruled, 1, [[$this->materialB, '100']], approved: false);

        $compliance = $this->kpis()['compliance'];

        $this->assertSame(['2', '3', '1', '1', '0', '1'], [$compliance['value'], $compliance['checked'], $compliance['fail'], $compliance['unknown'], $compliance['pass'], $compliance['not_applicable']]);
        $this->assertSame([['DASH-1', 'FAIL', 1], ['DASH-3', 'UNKNOWN', 0]], array_map(fn (array $row): array => [$row['formula']['code'], $row['status'], $row['failed']], $compliance['items']));
    }

    public function test_pending_approvals_count_what_waits_for_a_person(): void
    {
        FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 2, 'status' => FormulaStatus::LabTesting, 'reason' => 'Test change']);
        ProductionOrder::factory()->create(['formula_version_id' => $this->formula->versions()->first()->id]);

        $approvals = $this->kpis()['approvals'];

        // DASH-1 has an approved version and no cost approval yet: its cost waits for approval too.
        $this->assertSame(['1', '1', '0', '1', '3'], [$approvals['versions_in_lab'], $approvals['orders_to_approve'], $approvals['deviations_pending'], $approvals['costs_to_approve'], $approvals['value']]);
    }

    public function test_the_page_opens_at_once_and_the_figures_arrive_as_a_deferred_prop(): void
    {
        $version = null;
        $this->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$version): void {
            $page->component('Dashboard')->missing('kpis');
            $version = $page->toArray()['version'];
        });

        $reload = $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'kpis'])->assertOk();

        $this->assertSame(1, $reload->json('props.kpis.formula_count'));
        $this->assertArrayHasKey('compliance', $reload->json('props.kpis'));
    }
}
