<?php

namespace Tests\Feature\Pricing;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\TaxMode;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\Source;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsTierMaterials;
use Tests\TestCase;

/**
 * The price ladder screen against the real engine. Hand case (TEST-FIXTURE margins): unit cost 25 + factory 20 % = 30, + 2 distribution = 32,
 * wholesaler +25 % = 40, retailer +25 % = 50, VAT 14 % = 57; walked back from 57 the maximum unit cost is 25.
 */
class PriceLadderTest extends TestCase
{
    use BuildsTierMaterials, RefreshDatabase;

    private Formula $formula;

    private const RATES = [
        'margin_basis' => 'markup_on_cost', 'factory_margin_pct' => '20', 'distribution_cost_per_unit' => '2',
        'wholesaler_margin_pct' => '25', 'retailer_margin_pct' => '25',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxRegimeSeeder::class);
        $this->tierMaterials();
        $this->formula = Formula::factory()->create([
            'tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'code' => 'LAD-1', 'name' => 'Ladder product',
            'tax_mode' => TaxMode::Rate, 'tax_rate_pct' => '14',
            'unit_cost' => '25', 'unit_cost_source_id' => Source::factory()->create(['title' => 'Cost sheet'])->id, 'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function calculate(array $extra = []): TestResponse
    {
        return $this->postJson('/price-ladder/calculate', ['formula_id' => $this->formula->id, ...self::RATES, ...$extra]);
    }

    private function approvedVersionWithUsdMaterial(): void
    {
        $version = FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 1]);
        FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => 1, 'trade_material_id' => $this->surfactant->id, 'percent_ww' => '50']);
        FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => 2, 'trade_material_id' => $this->water->id, 'percent_ww' => '50']);
        $version->update(['status' => FormulaStatus::Approved]);
    }

    public function test_the_page_shows_the_unit_cost_tax_and_dollar_rate_of_the_product(): void
    {
        $this->get('/price-ladder?formula='.$this->formula->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PriceLadder/Index')
            ->where('product.code', 'LAD-1')
            ->where('product.unit_cost.value', '25')
            ->where('product.unit_cost.source_title', 'Cost sheet')
            ->where('product.tax.set', true)
            ->where('product.usd_rate.rate', '50'));
    }

    public function test_the_page_opens_without_a_product(): void
    {
        $this->get('/price-ladder')->assertOk()->assertInertia(fn (Assert $page) => $page->where('product', null));
    }

    public function test_cost_to_shelf_walks_the_waterfall_with_the_product_tax(): void
    {
        $answer = $this->calculate()->assertOk()->json('forward');

        $this->assertTrue($answer['ok']);
        $this->assertSame('57', $answer['consumer_price']['value']);
        $this->assertSame(['unit_cost', 'factory_price', 'wholesaler_purchase_price', 'retailer_purchase_price', 'price_ex_vat', 'shelf_price_incl_vat'], array_column($answer['steps'], 'key'));
        $this->assertSame(['30', '32', '40', '50'], array_map(fn (array $step): string => $step['traced']['value'], array_slice($answer['steps'], 1, 4)));
        $this->assertSame('7', $answer['vat_amount']['value']);
        $this->assertNotEmpty($answer['steps'][1]['traced']['trace']['inputs']);
    }

    public function test_shelf_to_maximum_cost_walks_it_back(): void
    {
        $answer = $this->calculate(['shelf_price' => '57', 'fill_kg' => '0.5', 'non_formula_cost_per_unit' => '5'])->assertOk()->json('reverse');

        $this->assertTrue($answer['ok']);
        $this->assertSame('25', $answer['max_unit_cost']);
        $this->assertSame('50', $answer['max_cost_per_kg']);
        $this->assertSame('40', $answer['max_formula_cost_per_kg']);
        $this->assertSame(['applied', '14'], [$answer['tax']['state'], $answer['tax']['rate_pct']]);
    }

    public function test_a_product_with_tax_disabled_has_no_tax_line_and_the_shelf_is_the_price_without_tax(): void
    {
        $this->formula->update(['tax_mode' => TaxMode::Disabled, 'tax_rate_pct' => null]);

        $answer = $this->calculate(['shelf_price' => '50', 'fill_kg' => '1'])->assertOk()->json();

        $this->assertSame('50', $answer['forward']['consumer_price']['value']);
        $this->assertNull($answer['forward']['vat_amount']);
        $this->assertSame('25', $answer['reverse']['max_unit_cost']);
        $this->assertSame('disabled', $answer['reverse']['tax']['state']);
    }

    public function test_missing_inputs_are_listed_by_name_and_nothing_is_priced(): void
    {
        $this->formula->update(['unit_cost' => null, 'unit_cost_source_id' => null, 'unit_cost_confidence' => null, 'tax_mode' => null, 'tax_rate_pct' => null]);

        $answer = $this->calculate(['retailer_margin_pct' => ''])->assertOk()->json('forward');

        $this->assertFalse($answer['ok']);
        $properties = array_column($answer['missing'], 'property');
        $this->assertContains('waterfall.unitCost', $properties);
        $this->assertContains('waterfall.retailerMarginPct', $properties);
        $this->assertTrue(collect($answer['missing'])->contains(fn (array $gap): bool => str_starts_with($gap['property'], 'tax:')));
        $this->assertNotNull(collect($answer['missing'])->firstWhere('property', 'waterfall.unitCost')['subject']);
    }

    public function test_the_dollar_moves_the_usd_priced_part_and_the_ladder_is_walked_again(): void
    {
        $this->approvedVersionWithUsdMaterial();

        $answer = $this->calculate(['fill_kg' => '0.5', 'fx_changes_pct' => ['10', '-10']])->assertOk()->json('fx');

        // 50 % x 100 EGP/kg (2 USD x 50) = 50 of a 50.5 EGP/kg material cost is USD-linked; x 0.5 kg = 25 EGP per unit.
        $this->assertSame('50', $answer['exposure']['usdLinkedPerKg']);
        $this->assertSame('50.5', $answer['exposure']['materialCostPerKg']);
        $this->assertSame('25', $answer['sensitivity']['usdLinkedPerUnit']);
        $up = $answer['sensitivity']['rows'][0];
        // +10 %: unit cost 25 + 2.5 = 27.5 -> 33 -> 35 -> 43.75 -> 54.6875 -> x 1.14 = 62.34375 (+5.34375, +9.375 %), dollar 55
        $this->assertSame(['10', '55', '27.5', '62.34375', '5.34375', '9.375'], [$up['changePct'], $up['usdRateAfter'], $up['unitCost'], $up['consumerPrice'], $up['deltaConsumerPrice'], $up['deltaConsumerPct']]);
        $down = $answer['sensitivity']['rows'][1];
        $this->assertSame(['22.5', '51.65625', '-5.34375'], [$down['unitCost'], $down['consumerPrice'], $down['deltaConsumerPrice']]);
    }

    public function test_without_the_fill_the_dollar_effect_on_the_unit_is_missing(): void
    {
        $this->approvedVersionWithUsdMaterial();

        $sensitivity = $this->calculate(['fx_changes_pct' => ['10']])->assertOk()->json('fx.sensitivity');

        $this->assertFalse($sensitivity['ok']);
        $this->assertSame('fx.fillKg', $sensitivity['missing'][0]['property']);
    }

    public function test_a_product_with_no_formula_version_has_no_dollar_exposure(): void
    {
        $answer = $this->calculate(['fill_kg' => '0.5', 'fx_changes_pct' => ['10']])->assertOk()->json('fx');

        $this->assertFalse($answer['exposure']['ok']);
        $this->assertTrue($answer['no_formula_version']);
    }

    public function test_the_request_is_validated(): void
    {
        $this->calculate(['factory_margin_pct' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('factory_margin_pct');
        $this->calculate(['margin_basis' => 'guess'])->assertUnprocessable()->assertJsonValidationErrors('margin_basis');
        $this->calculate(['fx_changes_pct' => ['1', '2', '3', '4', '5', '6']])->assertUnprocessable()->assertJsonValidationErrors('fx_changes_pct');
        $this->postJson('/price-ladder/calculate', [])->assertUnprocessable()->assertJsonValidationErrors('formula_id');
    }
}
