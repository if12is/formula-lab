<?php

namespace Database\Seeders;

use App\Actions\Sanad\CreateSanadDeviceAction;
use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\ItemKind;
use App\Enums\ProductTier;
use App\Enums\SourceKind;
use App\Enums\TaxMode;
use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\Price;
use App\Models\SanadDevice;
use App\Models\SanadPurchaseLine;
use App\Models\SanadSupplier;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo data for the packaging library (H-01), the cost report (I-08) and the Sanad publish (G-09), for the review screenshots and
 * the e2e specs. Placeholders for the screens, not reference data: the figures are hand-computable (material A 60 % at 10 EGP/kg from an
 * invoice, material B 40 % at 25 EGP/kg a manual entry: 6 + 10 = 16 EGP per kg; unit cost 18.4573). The formula's code comes from
 * COSTING_DEMO_CODE (default DEMO-COST); one that exists is left alone, so a spec asks for a fresh code to start from "cost not approved".
 * Run with `php artisan db:seed --class=CostingDemoSeeder`.
 */
class CostingDemoSeeder extends Seeder
{
    public const DEFAULT_CODE = 'DEMO-COST';

    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'costing-demo-seeder';

    public function run(FormulaVersionService $versions, CreateSanadDeviceAction $createDevice): void
    {
        $this->call(TaxRegimeSeeder::class);

        DB::transaction(function () use ($versions, $createDevice): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
            $this->packaging();
            $this->formula($tenant, $versions);
            if (! SanadDevice::query()->where('tenant_id', $tenant->id)->exists()) {
                $createDevice('Demo Sanad install', $tenant->id);
            }
        });
    }

    private function packaging(): void
    {
        if (TradeMaterial::query()->where('trade_name', 'Demo PET bottle 400 mL')->exists()) {
            return;
        }

        $datasheet = Source::factory()->create(['kind' => SourceKind::SupplierTds->value, 'title' => 'Demo bottle drawing', 'retrieved_at' => '2026-10-05']);
        $supplier = SanadSupplier::query()->firstOrCreate(['uuid' => 'fa4e0000-0000-4000-8000-000000000001'], [
            'tenant_id' => Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')])->id,
            'name' => 'Demo Packaging Co.', 'updated_at' => now(), 'synced_at' => now(),
        ]);
        $make = fn (array $attributes) => TradeMaterial::factory()->packaging($attributes['kind'])->create([...array_diff_key($attributes, ['kind' => 1]), 'status' => TradeMaterialStatus::Active]);
        $spec = ['packaging_source_id' => $datasheet->id, 'packaging_confidence' => Confidence::L2SupplierTds, 'sanad_supplier_uuid' => $supplier->uuid];

        $make(['kind' => ItemKind::Container, 'trade_name' => 'Demo PET bottle 400 mL', 'packaging_material' => 'pet', 'nominal_capacity_ml' => '400', 'brimful_capacity_ml' => '415.5', 'unit_weight_g' => '24.2', 'neck_finish' => '28/410', ...$spec]);
        $make(['kind' => ItemKind::Container, 'trade_name' => 'Demo glass jar 250 mL', 'packaging_material' => 'glass', 'nominal_capacity_ml' => '250', 'brimful_capacity_ml' => '262', 'unit_weight_g' => '165', 'neck_finish' => '63/400', ...$spec]);
        $make(['kind' => ItemKind::Closure, 'trade_name' => 'Demo flip-top cap 28/410', 'packaging_material' => 'hdpe', 'unit_weight_g' => '4.1', 'neck_finish' => '28/410', ...$spec]);
        $make(['kind' => ItemKind::Container, 'trade_name' => 'Demo aluminium can 150 mL', 'packaging_material' => 'aluminium', 'nominal_capacity_ml' => '150', 'brimful_capacity_ml' => '158', ...$spec]);
        $make(['kind' => ItemKind::ShippingCarton, 'trade_name' => 'Demo shipping carton 24 units', 'packaging_material' => 'carton', 'unit_weight_g' => '310', ...$spec]);
        // A label whose spec was never recorded: it reads "not recorded", not zero.
        $make(['kind' => ItemKind::Label, 'trade_name' => 'Demo front label']);
    }

    private function formula(Tenant $tenant, FormulaVersionService $versions): void
    {
        $code = (string) (env('COSTING_DEMO_CODE') ?: self::DEFAULT_CODE);
        if (Formula::query()->where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
            return;
        }

        $invoice = Source::query()->firstOrCreate(['kind' => SourceKind::SupplierInvoice->value, 'title' => 'INV-2026-0042'], ['retrieved_at' => '2026-11-01', 'url' => 'https://example.test/invoices/INV-2026-0042']);
        $quote = Source::query()->firstOrCreate(['kind' => SourceKind::SupplierTds->value, 'title' => 'Demo phone quote'], ['retrieved_at' => '2026-11-02']);
        $sheet = Source::query()->firstOrCreate(['kind' => SourceKind::Reference->value, 'title' => 'Demo cost sheet 2026-11'], ['retrieved_at' => '2026-11-10']);

        // Each formula has materials of its own, so a spec that moves a price never changes another spec's cost.
        $tag = $code === self::DEFAULT_CODE ? '' : " {$code}";
        $a = TradeMaterial::query()->firstOrCreate(['trade_name' => "Demo Cost Base{$tag}"], ['status' => TradeMaterialStatus::Active, 'function' => 'Base', 'substance_id' => null]);
        $b = TradeMaterial::query()->firstOrCreate(['trade_name' => "Demo Cost Active{$tag}"], ['status' => TradeMaterialStatus::Active, 'function' => 'Active', 'substance_id' => null]);
        if (! Price::query()->where('trade_material_id', $a->id)->exists()) {
            Price::factory()->create(['trade_material_id' => $a->id, 'amount' => '10', 'quoted_on' => '2026-11-01', 'source_id' => $invoice->id, 'confidence' => Confidence::L1CoaBatch]);
            Price::factory()->create(['trade_material_id' => $a->id, 'amount' => '9', 'quoted_on' => '2026-11-12', 'source_id' => $quote->id, 'confidence' => Confidence::L5Reference]);
            Price::factory()->create(['trade_material_id' => $b->id, 'amount' => '25', 'quoted_on' => '2026-11-02', 'source_id' => $quote->id, 'confidence' => Confidence::L2SupplierTds]);
            $a->update(['sanad_product_uuid' => (string) Str::uuid()]);
            SanadPurchaseLine::factory()->create(['tenant_id' => $tenant->id, 'sanad_product_uuid' => $a->sanad_product_uuid, 'sanad_supplier_uuid' => 'fa4e0000-0000-4000-8000-000000000001', 'purchase_date' => '2026-11-01', 'unit_cost' => '10.00', 'quantity' => '200', 'purchase_uom' => 'kg']);
        }

        $formula = Formula::factory()->create([
            'tenant_id' => $tenant->id, 'code' => $code, 'name' => 'Demo costed shampoo', 'product_type' => 'shampoo', 'tier' => ProductTier::Mid,
            'tax_mode' => TaxMode::Rate, 'tax_rate_pct' => '14',
            'unit_cost' => '18.4573', 'unit_cost_source_id' => $sheet->id, 'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);
        $versions->createVersion($formula, [
            ['trade_material_id' => $a->id, 'percent_ww' => '60', 'phase' => 'A'],
            ['trade_material_id' => $b->id, 'percent_ww' => '40', 'phase' => 'B'],
        ], null)->update(['status' => FormulaStatus::Approved]);
    }
}
