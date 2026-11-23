<?php

namespace Tests\Feature\Tiers;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Confidence;
use App\Enums\ProductTier;
use App\Models\Formula;
use App\Models\Price;
use App\Models\TierProfile;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTierMaterials;
use Tests\TestCase;

/**
 * The tiers screen's solve against the real optimizer (a Node process). Hand case: water 1 EGP/kg (0 % active) and a surfactant quoted
 * 2 USD at 50 EGP per USD = 100 EGP/kg (50 % active); active matter >= 10 % gives 20 % surfactant + 80 % water = 0.8 + 20 = 20.8 EGP/kg.
 */
class TierSolveTest extends TestCase
{
    use BuildsTierMaterials, RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tierMaterials();
        $this->formula = Formula::factory()->create(['tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'product_type' => 'shampoo', 'tier' => ProductTier::Economy]);
    }

    /** @param  array<string, mixed>  $constraints */
    private function profile(array $constraints = [], ?string $target = null): TierProfile
    {
        return TierProfile::factory()->create([
            'tenant_id' => $this->formula->tenant_id,
            'product_type' => 'shampoo',
            'tier' => ProductTier::Economy,
            'source_id' => $this->source->id,
            'profile' => [
                'targetRetailPrice' => ['per' => 'kg', 'value' => $target],
                'constraints' => [
                    'totalActiveMinPct' => '10', 'totalActiveMaxPct' => null, 'maxCostEGPperKg' => null,
                    'functionBounds' => [], 'requiredMaterials' => [], 'forbiddenMaterials' => [], 'materialBounds' => [], 'measuredRequirements' => [],
                    ...$constraints,
                ],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function solve(array $extra = []): TestResponse
    {
        return $this->postJson('/tiers/solve', ['formula_id' => $this->formula->id, 'tier' => 'economy', 'qs_material_id' => $this->water->id, ...$extra]);
    }

    public function test_it_finds_the_cheapest_formula_from_the_quotes_converted_with_the_dated_rate(): void
    {
        $this->profile();

        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('optimal', $answer['status']);
        $this->assertSame('20.8', $answer['cost_per_kg']);
        $this->assertSame([['Water', '80.000', '1'], ['Surfactant', '20.000', '100']], array_map(fn (array $line): array => [$line['material'], $line['percentWW'], $line['priceEGPperKg']], $answer['formula']));
        $this->assertSame('active.min', $answer['binding'][0]['id']);
        $this->assertSame('1.98', $answer['binding'][0]['dual']);
        $this->assertStringContainsString('10', $answer['binding'][0]['text']);
    }

    public function test_a_price_change_scenario_resolves_each_material_of_the_answer(): void
    {
        $this->profile();

        $answer = $this->solve(['scenario_pct' => '10'])->assertOk()->json('sensitivity');

        $surfactant = collect($answer)->firstWhere('material', 'Surfactant');
        // 100 -> 110 EGP/kg: 20 % x 10 = +2 EGP/kg on 20.8, i.e. 22.8 (+9.615 %).
        $this->assertSame('110', $surfactant['scenarioPriceEGPperKg']);
        $this->assertSame('22.8', $surfactant['scenarioCostPerKg']);
        $this->assertSame('2', $surfactant['costDeltaPerKg']);
        $this->assertSame('9.615', $surfactant['costDeltaPct']);
        $this->assertCount(2, $answer);
    }

    public function test_without_a_saved_profile_the_answer_is_missing_with_the_gap_named_and_no_formula(): void
    {
        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('missing', $answer['status']);
        $this->assertNull($answer['cost_per_kg']);
        $this->assertSame([], $answer['formula']);
        $this->assertSame('profile.totalActiveMinPct', $answer['missing'][0]['property']);
        $this->assertNotNull($answer['missing'][0]['subject']);
    }

    public function test_a_material_with_no_price_or_no_active_matter_is_left_out_and_listed(): void
    {
        $this->profile();
        $unpriced = TradeMaterial::factory()->active()->create(['trade_name' => 'No price']);
        $this->activeMatter($unpriced, '30');
        $noActive = TradeMaterial::factory()->active()->create(['trade_name' => 'No active matter']);
        Price::factory()->create(['trade_material_id' => $noActive->id, 'amount' => '5', 'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds]);

        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('optimal', $answer['status']);
        $this->assertSame('20.8', $answer['cost_per_kg']);
        $this->assertEqualsCanonicalizing(
            [['No active matter', 'activeMatterPct'], ['No price', 'price']],
            array_map(fn (array $row): array => [$row['material'], $row['property']], $answer['excluded']),
        );
    }

    public function test_an_estimate_price_is_not_used_and_the_material_is_excluded(): void
    {
        $this->profile();
        Price::query()->where('trade_material_id', $this->surfactant->id)->update(['confidence' => Confidence::L7Estimate]);

        $answer = $this->solve()->assertOk()->json();

        $this->assertContains('Surfactant', array_column($answer['excluded'], 'material'));
        $this->assertSame('infeasible', $answer['status']);
    }

    public function test_a_cost_limit_below_the_cheapest_formula_says_how_far_it_is(): void
    {
        $this->profile(['maxCostEGPperKg' => '15']);

        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('infeasible', $answer['status']);
        $this->assertNull($answer['cost_per_kg']);
        $this->assertNotEmpty($answer['conflicts']);
        $this->assertStringContainsString('5.8', implode(' ', array_column($answer['conflicts'], 'text')));
    }

    public function test_a_forbidden_material_is_not_offered(): void
    {
        $this->profile(['forbiddenMaterials' => [$this->surfactant->id]]);

        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('infeasible', $answer['status']);
    }

    public function test_the_target_price_is_walked_back_to_a_cost_cap_from_the_product_tax_and_the_typed_margins(): void
    {
        $this->profile(target: '50');
        $this->formula->update(['tax_mode' => 'disabled']);

        $answer = $this->solve(['reverse' => [
            'margin_basis' => 'markup_on_cost', 'factory_margin_pct' => '20', 'distribution_cost_per_unit' => '0',
            'wholesaler_margin_pct' => '10', 'retailer_margin_pct' => '10', 'non_formula_cost_per_unit' => '5',
        ]])->assertOk()->json();

        // shelf 50 (no tax) / 1.1 / 1.1 / 1.2 = 34.4352617..., less 5 per kg of non-formula cost = 29.4352617...
        $this->assertSame('optimal', $answer['status']);
        $this->assertStringStartsWith('29.43526170798', $answer['reverse']['max_formula_cost_per_kg']);
    }

    public function test_a_target_price_without_the_margins_is_missing_and_names_each(): void
    {
        $this->profile(target: '50');
        $this->formula->update(['tax_mode' => 'disabled']);

        $answer = $this->solve()->assertOk()->json();

        $this->assertSame('missing', $answer['status']);
        $this->assertContains('reversePricing.retailerMarginPct', array_column($answer['missing'], 'property'));
    }

    public function test_a_product_without_a_tax_setting_cannot_walk_the_target_price_back(): void
    {
        $this->profile(target: '50');

        $answer = $this->solve(['reverse' => ['margin_basis' => 'markup_on_cost', 'factory_margin_pct' => '20', 'distribution_cost_per_unit' => '0', 'wholesaler_margin_pct' => '10', 'retailer_margin_pct' => '10', 'non_formula_cost_per_unit' => '5']])->assertOk()->json();

        $this->assertSame('missing', $answer['status']);
        $this->assertTrue(collect($answer['missing'])->contains(fn (array $gap): bool => $gap['reason'] === 'tax.setting_missing' || $gap['property'] === 'reversePricing.vatRatePct'));
    }

    public function test_the_request_is_validated(): void
    {
        $this->postJson('/tiers/solve', ['tier' => 'economy'])->assertUnprocessable()->assertJsonValidationErrors('formula_id');
        $this->solve(['scenario_pct' => 'ten'])->assertUnprocessable()->assertJsonValidationErrors('scenario_pct');
        $this->solve(['reverse' => ['retailer_margin_pct' => '-5']])->assertUnprocessable()->assertJsonValidationErrors('reverse.retailer_margin_pct');
    }
}
