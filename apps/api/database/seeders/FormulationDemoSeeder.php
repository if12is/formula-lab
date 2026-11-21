<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo materials and one formula with three versions for the formulation screens (S-03): the Playwright specs and the
 * review screenshots use them. Every row is named "Demo ..." / "DEMO-...", and the whole set is skipped when it is
 * already there (approved versions cannot be deleted, so it is never rebuilt). The figures are placeholders for the
 * screens, not reference data. Run with `php artisan db:seed --class=FormulationDemoSeeder`.
 */
class FormulationDemoSeeder extends Seeder
{
    public const FORMULA_CODE = 'DEMO-SHAMPOO';

    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'formulation-demo-seeder';

    public function run(FormulaVersionService $versions): void
    {
        DB::transaction(function () use ($versions): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $this->seed($versions);
        });
    }

    private function seed(FormulaVersionService $versions): void
    {
        $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);

        if (Formula::query()->where('tenant_id', $tenant->id)->where('code', self::FORMULA_CODE)->exists()) {
            return;
        }

        $tds = Source::factory()->create(['kind' => 'supplier_tds', 'title' => 'Demo supplier data sheet', 'retrieved_at' => '2026-03-01']);
        $reference = Source::factory()->create(['kind' => 'reference', 'title' => 'Demo reference book', 'retrieved_at' => '2026-01-15']);
        $coa = Source::factory()->create(['kind' => 'coa', 'title' => 'Demo batch certificate', 'retrieved_at' => '2026-04-02']);

        $laureth = Substance::query()->firstOrCreate(['cas' => '68585-34-2'], ['inci' => 'SODIUM LAURETH SULFATE', 'name_en' => 'Sodium laureth sulfate']);
        $aqua = Substance::query()->firstOrCreate(['cas' => '7732-18-5'], ['inci' => 'AQUA', 'name_en' => 'Water']);

        $sles = TradeMaterial::factory()->for($laureth)->create(['trade_name' => 'Demo SLES', 'grade' => '70%', 'function' => 'Surfactant', 'status' => TradeMaterialStatus::Active]);
        $water = TradeMaterial::factory()->for($aqua)->create(['trade_name' => 'Demo Water', 'grade' => 'Demineralised', 'function' => 'Solvent', 'status' => TradeMaterialStatus::Active]);
        $salt = TradeMaterial::factory()->create(['trade_name' => 'Demo Salt', 'grade' => 'Technical', 'function' => 'Viscosity builder', 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);
        $fragrance = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Demo Fragrance', 'grade' => null, 'function' => 'Fragrance', 'status' => TradeMaterialStatus::Draft]);

        // SLES: two values at the same confidence that disagree (a conflict), plus an older, weaker one.
        foreach ([
            ['value' => '1.04', 'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-03-01', 'source_id' => $tds->id, 'lot_no' => null],
            ['value' => '1.06', 'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-03-01', 'source_id' => $coa->id, 'lot_no' => 'LOT-0412'],
            ['value' => '1.05', 'confidence' => Confidence::L5Reference, 'valid_from' => '2026-01-15', 'source_id' => $reference->id, 'lot_no' => null],
        ] as $row) {
            PropertyValue::factory()->create([...$row, 'entity_type' => 'trade_material', 'entity_id' => $sles->id, 'property' => 'density', 'unit' => 'g/mL']);
        }
        PropertyValue::factory()->range('68', '72')->create(['entity_type' => 'trade_material', 'entity_id' => $sles->id, 'property' => 'active_matter', 'unit' => '%w/w', 'confidence' => Confidence::L2SupplierTds, 'source_id' => $tds->id]);

        Price::factory()->create(['trade_material_id' => $sles->id, 'amount' => '38.5', 'quoted_on' => '2026-03-01', 'source_id' => $tds->id, 'confidence' => Confidence::L2SupplierTds]);
        Price::factory()->create(['trade_material_id' => $sles->id, 'amount' => '36', 'quoted_on' => '2026-01-10', 'source_id' => $reference->id, 'confidence' => Confidence::L5Reference]);
        Price::factory()->create(['trade_material_id' => $water->id, 'amount' => '0.4', 'quoted_on' => '2026-03-01', 'source_id' => $tds->id, 'confidence' => Confidence::L3Standard]);
        Price::factory()->create(['trade_material_id' => $fragrance->id, 'amount' => '420', 'quoted_on' => '2026-04-01', 'source_id' => $reference->id, 'confidence' => Confidence::L7Estimate]);

        $formula = Formula::factory()->create([
            'tenant_id' => $tenant->id, 'code' => self::FORMULA_CODE, 'name' => 'Demo daily shampoo', 'product_type' => 'shampoo', 'tier' => ProductTier::Mid,
        ]);

        $versions->createVersion($formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '12', 'phase' => 'A'],
            ['trade_material_id' => $salt->id, 'percent_ww' => '1', 'phase' => 'B'],
            ['trade_material_id' => $water->id, 'percent_ww' => '87', 'is_qs' => true, 'phase' => 'A'],
        ], null)->update(['status' => FormulaStatus::Approved]);

        $versions->createVersion($formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '14', 'phase' => 'A'],
            ['trade_material_id' => $salt->id, 'percent_ww' => '1.5', 'phase' => 'B'],
            ['trade_material_id' => $fragrance->id, 'percent_ww' => '0.5', 'phase' => 'C'],
            ['trade_material_id' => $water->id, 'percent_ww' => '84', 'is_qs' => true, 'phase' => 'A'],
        ], 'More foam, salt for viscosity and a fragrance');

        $versions->createVersion($formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '14', 'phase' => 'A'],
            ['trade_material_id' => $salt->id, 'percent_ww' => '1.5', 'phase' => 'B'],
            ['trade_material_id' => $fragrance->id, 'percent_ww' => '0.3', 'phase' => 'C'],
            ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true, 'phase' => 'A'],
        ], 'Less fragrance, water balances the rest');
    }
}
