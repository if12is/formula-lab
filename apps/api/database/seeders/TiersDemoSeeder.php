<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Enums\SourceKind;
use App\Enums\TaxMode;
use App\Enums\TradeMaterialStatus;
use App\Models\ExchangeRate;
use App\Models\Formula;
use App\Models\FormulaPublication;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Costing\CostApprovalService;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the tiers screen (S-05), the price ladder (S-13) and the home page figures (S-15): the screenshots and the Playwright specs use it.
 * FICTIONAL, hand-computable numbers for the screens, not business data: water 1 EGP/kg (0 % active), a surfactant quoted 2 USD/kg at 50 EGP per USD
 * = 100 EGP/kg (50 % active), salt 3 EGP/kg last quoted in 2020 (an old price). The product DEMO-TIER is 20 % surfactant + 80 % water = 20.8 EGP/kg, its
 * cost approved at that with a unit cost of 25 and a published price of 30; the surfactant is then re-quoted at 2.2 USD (110 EGP/kg), so today's cost is
 * 22.8 (+9.615 %). DEMO-BARE has a version but no unit cost, no tax setting and no price: the screens must show every gap. It creates no tier profile
 * (the specs type one). Idempotent. Run with `php artisan db:seed --class=TiersDemoSeeder`.
 */
class TiersDemoSeeder extends Seeder
{
    public const CODE = 'DEMO-TIER';

    public const BARE_CODE = 'DEMO-BARE';

    public const PRODUCT_TYPE = 'demo_shampoo';

    private const LOCK_KEY = 'tiers-demo-seeder';

    public function run(FormulaVersionService $versions, CostApprovalService $approvals): void
    {
        $this->call(TaxRegimeSeeder::class);

        DB::transaction(function () use ($versions, $approvals): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $this->seed($versions, $approvals);
        });
    }

    private function seed(FormulaVersionService $versions, CostApprovalService $approvals): void
    {
        if (Formula::query()->where('code', self::CODE)->exists()) {
            return;
        }

        $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
        $tds = Source::factory()->create(['kind' => SourceKind::SupplierTds->value, 'title' => 'Demo tiers supplier sheet', 'retrieved_at' => '2026-11-02']);
        $costSheet = Source::factory()->create(['kind' => SourceKind::Reference->value, 'title' => 'Demo tiers cost sheet', 'retrieved_at' => '2026-11-10']);

        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '50', 'rate_date' => '2026-11-01', 'confidence' => Confidence::L2SupplierTds, 'source_id' => $tds->id]);

        $water = $this->material('Zz Tier Water', 'Solvent', '1', 'EGP', '2026-11-02', '0', $tds);
        $surfactant = $this->material('Zz Tier Surfactant', 'Surfactant', '2', 'USD', '2026-11-02', '50', $tds);
        $salt = $this->material('Zz Tier Salt', 'Additive', '3', 'EGP', '2020-01-01', '0', $tds);

        $formula = Formula::query()->create([
            'tenant_id' => $tenant->id, 'code' => self::CODE, 'name' => 'Demo tier shampoo', 'product_type' => self::PRODUCT_TYPE, 'tier' => ProductTier::Economy,
            'tax_mode' => TaxMode::Rate, 'tax_rate_pct' => '14',
            'unit_cost' => '25', 'unit_cost_source_id' => $costSheet->id, 'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);
        $versions->createVersion($formula, [
            ['trade_material_id' => $surfactant->id, 'percent_ww' => '20'],
            ['trade_material_id' => $water->id, 'percent_ww' => '80'],
        ], null)->update(['status' => FormulaStatus::Approved]);

        $approval = $approvals->approve($formula, null, null);
        FormulaPublication::factory()->create(['cost_approval_id' => $approval->id, 'details' => ['sell_price' => '30']]);
        Price::factory()->create(['trade_material_id' => $surfactant->id, 'amount' => '2.2', 'currency' => 'USD', 'quoted_on' => '2026-11-15', 'source_id' => $tds->id, 'confidence' => Confidence::L2SupplierTds]);

        $bare = Formula::query()->create(['tenant_id' => $tenant->id, 'code' => self::BARE_CODE, 'name' => 'Demo bare product', 'product_type' => 'demo_bare']);
        $versions->createVersion($bare, [
            ['trade_material_id' => $salt->id, 'percent_ww' => '50'],
            ['trade_material_id' => TradeMaterial::factory()->create(['trade_name' => 'Zz Tier Unpriced', 'status' => TradeMaterialStatus::Active, 'substance_id' => null])->id, 'percent_ww' => '50'],
        ], null);
    }

    private function material(string $name, string $function, string $price, string $currency, string $quotedOn, string $active, Source $source): TradeMaterial
    {
        $material = TradeMaterial::factory()->create(['trade_name' => $name, 'function' => $function, 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);
        Price::factory()->create(['trade_material_id' => $material->id, 'amount' => $price, 'currency' => $currency, 'quoted_on' => $quotedOn, 'source_id' => $source->id, 'confidence' => Confidence::L2SupplierTds]);
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'active_matter', 'value' => $active, 'unit' => '%w/w',
            'source_id' => $source->id, 'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-11-02',
        ]);

        return $material;
    }
}
