<?php

namespace Tests\Feature\Tiers;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\ProductTier;
use App\Models\Formula;
use App\Models\Source;
use App\Models\TierProfile;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The tier profile of a product type: written with a source, saved once per type and tier, and read back as the screen's form. */
class TierProfileTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formula = Formula::factory()->create(['tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'product_type' => 'shampoo', 'tier' => ProductTier::Mid]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'formula_id' => $this->formula->id,
            'tier' => 'economy',
            'total_active_min_pct' => '10',
            'total_active_max_pct' => '',
            'max_cost_egp_per_kg' => '',
            'target_per' => 'kg',
            'target_value' => '',
            'forbidden' => [],
            'bounds' => [],
            'source_id' => Source::factory()->create()->id,
            ...$overrides,
        ];
    }

    public function test_the_page_opens_empty_without_a_product(): void
    {
        $this->get('/tiers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Tiers/Index')->where('product', null)->where('profile', null)->where('tier', 'economy'));
    }

    public function test_an_unsaved_profile_is_an_empty_form_never_default_figures(): void
    {
        $this->get('/tiers?formula='.$this->formula->id.'&tier=premium')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tier', 'premium')
            ->where('product.product_type', 'shampoo')
            ->where('profile.saved', false)
            ->where('profile.total_active_min_pct', '')
            ->where('profile.max_cost_egp_per_kg', '')
            ->where('profile.target_value', '')
            ->where('profile.forbidden', [])
            ->where('profile.source', null));
    }

    public function test_the_tier_defaults_to_the_one_of_the_product(): void
    {
        $this->get('/tiers?formula='.$this->formula->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('tier', 'mid'));
    }

    public function test_saving_keeps_the_figures_as_decimal_text_with_the_source_and_empty_ones_as_null(): void
    {
        $material = TradeMaterial::factory()->active()->create(['trade_name' => 'Salt']);
        $payload = $this->payload([
            'total_active_min_pct' => '10.50',
            'target_value' => '45',
            'bounds' => [['material_id' => $material->id, 'min' => '1', 'max' => '', 'required' => true]],
        ]);

        $this->put('/tiers/profile', $payload)->assertRedirect(route('tiers.index', ['formula' => $this->formula->id, 'tier' => 'economy']));

        $saved = TierProfile::query()->sole();
        $this->assertSame('shampoo', $saved->product_type);
        $this->assertSame(ProductTier::Economy, $saved->tier);
        $this->assertSame($payload['source_id'], $saved->source_id);
        $this->assertSame('10.5', $saved->profile['constraints']['totalActiveMinPct']);
        $this->assertNull($saved->profile['constraints']['totalActiveMaxPct']);
        $this->assertNull($saved->profile['constraints']['maxCostEGPperKg']);
        $this->assertSame('45', $saved->profile['targetRetailPrice']['value']);
        $this->assertSame([$material->id], $saved->profile['constraints']['requiredMaterials']);
        $this->assertEquals([['materialId' => $material->id, 'min' => '1', 'max' => null]], $saved->profile['constraints']['materialBounds']);
    }

    public function test_a_second_save_replaces_the_profile_of_the_same_type_and_tier(): void
    {
        $this->put('/tiers/profile', $this->payload())->assertRedirect();
        $this->put('/tiers/profile', $this->payload(['total_active_min_pct' => '12']))->assertRedirect();

        $this->assertSame(1, TierProfile::query()->count());
        $this->assertSame('12', TierProfile::query()->sole()->profile['constraints']['totalActiveMinPct']);
    }

    public function test_another_tier_of_the_same_type_is_its_own_profile(): void
    {
        $this->put('/tiers/profile', $this->payload())->assertRedirect();
        $this->put('/tiers/profile', $this->payload(['tier' => 'premium', 'total_active_min_pct' => '20']))->assertRedirect();

        $this->assertSame(2, TierProfile::query()->count());
    }

    public function test_a_figure_without_a_source_is_refused(): void
    {
        $this->put('/tiers/profile', $this->payload(['source_id' => null]))->assertSessionHasErrors('source_id');

        $this->assertSame(0, TierProfile::query()->count());
    }

    public function test_an_empty_profile_may_be_saved_without_a_source(): void
    {
        $this->put('/tiers/profile', $this->payload(['source_id' => null, 'total_active_min_pct' => '']))->assertSessionDoesntHaveErrors();

        $this->assertSame(1, TierProfile::query()->count());
    }

    public function test_a_figure_that_is_not_a_plain_decimal_is_refused(): void
    {
        $this->put('/tiers/profile', $this->payload(['total_active_min_pct' => '1e3']))->assertSessionHasErrors('total_active_min_pct');
        $this->put('/tiers/profile', $this->payload(['target_per' => 'L']))->assertSessionHasErrors('target_per');
        $this->put('/tiers/profile', $this->payload(['tier' => 'gold']))->assertSessionHasErrors('tier');
    }

    public function test_the_saved_profile_comes_back_as_the_form(): void
    {
        $material = TradeMaterial::factory()->active()->create(['trade_name' => 'Salt']);
        $forbidden = TradeMaterial::factory()->active()->create(['trade_name' => 'Banned']);
        $payload = $this->payload(['forbidden' => [$forbidden->id], 'bounds' => [['material_id' => $material->id, 'min' => '2', 'max' => '4', 'required' => false]]]);
        $this->put('/tiers/profile', $payload);

        $this->get('/tiers?formula='.$this->formula->id.'&tier=economy')->assertInertia(fn (Assert $page) => $page
            ->where('profile.saved', true)
            ->where('profile.total_active_min_pct', '10')
            ->where('profile.forbidden.0.label', 'Banned')
            ->where('profile.bounds.0.label', 'Salt')
            ->where('profile.bounds.0.required', false)
            ->where('profile.source.value', $payload['source_id']));
    }
}
