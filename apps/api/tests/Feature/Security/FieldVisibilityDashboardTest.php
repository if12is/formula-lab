<?php

namespace Tests\Feature\Security;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\TestCase;

/** U-07: the home page's figures. A role without the cost group is sent neither the stale-price, the price-move nor the costs-to-approve figures. */
class FieldVisibilityDashboardTest extends TestCase
{
    use BuildsCostedFormulas, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 10:00:00');
        $this->costedProduct(app(EnsureDefaultTenantAction::class)()->getKey(), ['code' => 'DASH-1', 'name' => 'Costed shampoo', 'product_type' => 'shampoo', 'unit_cost' => '20']);
    }

    /** The first load (no figures yet: they are deferred) and the deferred reload that carries them. */
    private function kpis(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $version = $this->actingAs($user)->get('/')->assertOk()->original->getData()['page']['version'];
        $reload = $this->actingAs($user)->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'kpis'])->assertOk();

        return [$reload->json('props.kpis'), $reload->getContent()];
    }

    public function test_the_production_role_gets_the_non_money_figures_and_none_of_the_cost_ones(): void
    {
        [$kpis, $body] = $this->kpis('production');

        $this->assertSame(1, $kpis['formula_count']);
        $this->assertArrayHasKey('quality', $kpis);
        $this->assertArrayHasKey('compliance', $kpis);
        $this->assertNull($kpis['stale']);
        $this->assertNull($kpis['impact']);
        $this->assertArrayNotHasKey('costs_to_approve', $kpis['approvals']);
        foreach (['18.4573', '16.6', 'currentCostPerKg', 'approvedCostPerKg', 'marginNowPct', 'sell_price', 'costReport', 'cost-report'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "the dashboard carries {$leak}");
        }
    }

    public function test_the_owner_and_the_accountant_get_the_cost_figures(): void
    {
        foreach (['owner', 'accountant'] as $role) {
            [$kpis] = $this->kpis($role);

            $this->assertNotNull($kpis['stale'], $role);
            $this->assertNotNull($kpis['impact'], $role);
            $this->assertArrayHasKey('costs_to_approve', $kpis['approvals'], $role);
        }
    }

    public function test_the_first_load_of_the_home_page_holds_no_figures_for_anyone(): void
    {
        $props = $this->actingAs(User::factory()->create(['role' => 'production']))->get('/')->original->getData()['page']['props'];

        $this->assertArrayNotHasKey('kpis', $props);
        $this->assertSame(['sees_cost' => false, 'sees_price' => false, 'sees_percentage' => false], $props['fieldAudience']);
    }
}
