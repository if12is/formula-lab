<?php

namespace Database\Seeders;

use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\ProductFamily;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo and e2e only (K-05, N-12): two formulas to open in the editor, one of an alcohol disinfectant family and one of a shampoo, each
 * with two fictional materials that carry no composition and no price. They stand for nothing real. Skipped when already there.
 * Run with `php artisan db:seed --class=DisinfectantDemoSeeder`.
 */
class DisinfectantDemoSeeder extends Seeder
{
    public const ALCOHOL_CODE = 'DEMO-DISINFECTANT';

    public const SHAMPOO_CODE = 'DEMO-DISINF-SHAMPOO';

    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'disinfectant-demo-seeder';

    public function run(FormulaVersionService $versions): void
    {
        $this->call(ProductFamilySeeder::class);

        DB::transaction(function () use ($versions): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
            $active = TradeMaterial::query()->firstOrCreate(['trade_name' => 'Disinfectant demo Active'], ['function' => 'Active', 'status' => TradeMaterialStatus::Active, 'composition_complete' => false, 'is_mixture' => false]);
            $water = TradeMaterial::query()->firstOrCreate(['trade_name' => 'Disinfectant demo Water'], ['function' => 'Water', 'status' => TradeMaterialStatus::Active, 'composition_complete' => false, 'is_mixture' => false]);

            foreach ([self::ALCOHOL_CODE => ['Demo alcohol disinfectant', 'alcohol_disinfectant'], self::SHAMPOO_CODE => ['Demo shampoo (disinfectant notice)', 'shampoo']] as $code => [$name, $family]) {
                if (Formula::query()->where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
                    continue;
                }

                $formula = Formula::factory()->create([
                    'tenant_id' => $tenant->id, 'code' => $code, 'name' => $name, 'product_type' => $family,
                    'product_family_id' => ProductFamily::query()->where('code', $family)->value('id'),
                ]);
                $versions->createVersion($formula, [
                    ['trade_material_id' => $active->id, 'percent_ww' => '70'],
                    ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true],
                ], null);
            }
        });
    }
}
