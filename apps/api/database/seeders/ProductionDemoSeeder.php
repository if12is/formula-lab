<?php

namespace Database\Seeders;

use App\Actions\Sanad\AcknowledgeSanadOutboxItemAction;
use App\Actions\Sanad\CreateSanadDeviceAction;
use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\ItemKind;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaVersion;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\ProductionOrder;
use App\Models\PropertyValue;
use App\Models\SanadDevice;
use App\Models\Source;
use App\Models\Substance;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaProcessService;
use App\Services\Formula\FormulaVersionService;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo data for the production screens (phase Q), for the Playwright specs and the review screenshots. Placeholders for the screens, not
 * reference data; the figures are hand-computable:
 *
 *  - DEMO-PROD: an approved shampoo (surfactant 12 %, salt 1.5 %, water q.s.) with manufacturing steps and sourced pH limits, linked to Sanad,
 *    its unit cost 18.4573 approved. A 2.5 kg batch at a 1 g balance weighs 2162 g water, 300 g surfactant, 38 g salt (the halves round to even).
 *    Orders: one draft; one approved, sent and acknowledged by Sanad with its batch in progress (a surfactant weighed 330 g against 300 g = +10 %
 *    with a pending deviation, and a pH reading of 6.8 against the limits 5.5 to 6.5); one completed batch with its real cost.
 *  - DEMO-PROD-NEUT: an approved LABSA neutralisation with KOH, two batches: one whose lots have certificates (alkali 200 g at the reference
 *    values, 237.5 g at the lot values) and one whose acid lot has no certificate (the calculation stops and says so).
 *
 * Skipped when DEMO-PROD already exists. Orders are approved by writing the status directly: nobody is signed in while seeding.
 * Run with `php artisan db:seed --class=ProductionDemoSeeder`.
 */
class ProductionDemoSeeder extends Seeder
{
    public const FORMULA_CODE = 'DEMO-PROD';

    public const NEUTRAL_CODE = 'DEMO-PROD-NEUT';

    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'production-demo-seeder';

    public function run(FormulaVersionService $versions, FormulaProcessService $process, ProductionOrderService $orders, ProductionBatchService $batches, CreateSanadDeviceAction $createDevice): void
    {
        $this->call(TaxRegimeSeeder::class);

        DB::transaction(function () use ($versions, $process, $orders, $batches, $createDevice): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);

            $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
            if (Formula::query()->where('tenant_id', $tenant->id)->where('code', self::FORMULA_CODE)->exists()) {
                return;
            }

            $sheet = Source::query()->where('title', 'Demo supplier data sheet')->first() ?? Source::factory()->create(['kind' => 'supplier_tds', 'title' => 'Demo supplier data sheet', 'retrieved_at' => '2026-03-01']);
            $coa = Source::query()->where('title', 'Demo batch certificate')->first() ?? Source::factory()->create(['kind' => 'coa', 'title' => 'Demo batch certificate', 'retrieved_at' => '2026-04-02']);

            $device = SanadDevice::query()->where('tenant_id', $tenant->id)->orderBy('created_at')->first() ?? $createDevice('Demo Sanad install', $tenant->id)['device'];

            $this->shampoo($tenant, $sheet, $device, $versions, $process, $orders, $batches);
            $this->neutralisation($tenant, $sheet, $coa, $versions, $orders, $batches);
        });
    }

    private function approve(ProductionOrder $order): ProductionOrder
    {
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        return $order;
    }

    private function weigh(ProductionBatchService $batches, ProductionBatch $batch, string $material, string $actual, ?string $lot = null, ?string $unitCost = null): void
    {
        $line = $batch->lines()->get()->first(fn (ProductionBatchLine $line): bool => $line->tradeMaterial->trade_name === $material);
        $batches->weigh($batch, $line, ['actual_quantity' => $actual, 'lot_number' => $lot, 'unit_cost' => $unitCost, 'weighed_by' => 'Demo Operator']);
    }

    private function shampoo(Tenant $tenant, Source $sheet, SanadDevice $device, FormulaVersionService $versions, FormulaProcessService $process, ProductionOrderService $orders, ProductionBatchService $batches): void
    {
        $make = fn (string $name, string $function, ?string $kind = null): TradeMaterial => TradeMaterial::factory()->create([
            'trade_name' => $name, 'function' => $function, 'status' => TradeMaterialStatus::Active, 'substance_id' => null, 'sanad_product_uuid' => (string) Str::uuid(),
        ]);
        $surfactant = $make('Demo Prod Surfactant', 'Surfactant');
        $salt = $make('Demo Prod Salt', 'Viscosity builder');
        $water = $make('Demo Prod Aqua', 'Solvent');
        $bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Demo Prod Bottle 400 mL', 'sanad_product_uuid' => (string) Str::uuid()]);

        $formula = Formula::factory()->create([
            'tenant_id' => $tenant->id, 'code' => self::FORMULA_CODE, 'name' => 'Demo production shampoo', 'product_type' => 'shampoo',
            'sanad_product_uuid' => (string) Str::uuid(), 'unit_cost' => '18.4573', 'unit_cost_source_id' => $sheet->id, 'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);
        $version = $versions->createVersion($formula, [
            ['trade_material_id' => $surfactant->id, 'percent_ww' => '12', 'phase' => 'water'],
            ['trade_material_id' => $salt->id, 'percent_ww' => '1.5', 'phase' => 'cool_down'],
            ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true, 'phase' => 'water'],
        ], null);
        $process->replace($version, [
            ['kind' => 'heat', 'phase' => 'water', 'temperature_c' => '40', 'duration_minutes' => '15', 'note' => null, 'checks' => []],
            ['kind' => 'add', 'phase' => 'water', 'line_no' => '3', 'mixing_speed_rpm' => '60', 'duration_minutes' => '10', 'checks' => []],
            ['kind' => 'add', 'phase' => 'water', 'line_no' => '1', 'mixing_speed_rpm' => '60', 'duration_minutes' => '20', 'checks' => [
                ['property' => 'ph', 'unit' => 'pH', 'limit_min' => '5.5', 'limit_max' => '6.5', 'source_id' => $sheet->id, 'confidence' => Confidence::L2SupplierTds->value],
                ['property' => 'viscosity', 'unit' => 'mPa·s'],
            ]],
            ['kind' => 'add', 'phase' => 'cool_down', 'line_no' => '2', 'temperature_c' => '35', 'mixing_speed_rpm' => '40', 'duration_minutes' => '10', 'note' => 'Add the salt slowly', 'checks' => []],
        ]);
        $version->refresh()->update(['status' => FormulaStatus::Approved]);
        FormulaCostApproval::factory()->create([
            'formula_id' => $formula->id, 'formula_version_id' => $version->id, 'unit_cost' => '18.4573', 'unit_cost_source_id' => $sheet->id,
            'unit_cost_confidence' => Confidence::L2SupplierTds, 'approved_at' => now(),
        ]);

        $data = ['batch_size' => '2.5', 'batch_unit' => 'kg', 'scale_resolution_g' => '1', 'units_count' => '500', 'planned_on' => '2026-11-25', 'expiry_date' => '2028-11-24'];

        // A draft order, to approve.
        $orders->create($version->refresh(), [...$data, 'notes' => 'Demo draft order', 'packaging' => [['trade_material_id' => $bottle->id, 'quantity' => '500']]]);

        // Approved, sent, acknowledged by Sanad, its batch in progress with a pending deviation and a failed reading.
        $sent = $this->approve($orders->create($version->refresh(), [...$data, 'planned_on' => '2026-11-26', 'packaging' => [['trade_material_id' => $bottle->id, 'quantity' => '500']]]));
        $orders->send($sent);
        app(AcknowledgeSanadOutboxItemAction::class)($device, ['id' => $sent->fresh()->sanad_outbox_id, 'status' => 'applied', 'result' => ['consume_status' => 'consumed', 'receive_status' => 'received', 'product_batch_uuid' => (string) Str::uuid()]]);
        $batch = $batches->start($sent->fresh(), 'Demo Operator', '2');
        $this->weigh($batches, $batch, 'Demo Prod Aqua', '2162', 'DEMO-LOT-W1', '0.5');
        $this->weigh($batches, $batch, 'Demo Prod Surfactant', '330', 'DEMO-LOT-7731', '51.5');
        $batches->check($batch, ['step_no' => 3, 'check_no' => 1, 'value' => '6.8', 'measured_by' => 'Demo Operator', 'measured_at' => '2026-11-26T10:30']);
        $batches->deviate($batch, ['kind' => 'weight', 'subject' => 'line:2', 'planned' => '300', 'actual' => '330', 'unit' => 'g', 'reason' => 'Topped up after a spill on the balance', 'recorded_by_name' => 'Demo Operator']);

        // A completed batch with its real cost.
        $done = $this->approve($orders->create($version->refresh(), [...$data, 'planned_on' => '2026-11-20', 'packaging' => [['trade_material_id' => $bottle->id, 'quantity' => '10']]]));
        $finished = $batches->start($done, 'Demo Operator', '2');
        $this->weigh($batches, $finished, 'Demo Prod Aqua', '2162', 'DEMO-LOT-W2', '0.5');
        $this->weigh($batches, $finished, 'Demo Prod Surfactant', '300', 'DEMO-LOT-7731', '51.5');
        $this->weigh($batches, $finished, 'Demo Prod Salt', '38', 'DEMO-LOT-S1', '10');
        $this->weigh($batches, $finished, 'Demo Prod Bottle 400 mL', '10', null, '2.5');
        $batches->results($finished, ['actual_waste_g' => '48', 'actual_units' => '2', 'actual_conversion_egp' => '10', 'conversion_note' => 'Demo labour and energy sheet']);
        $batches->complete($finished->fresh());
    }

    private function neutralisation(Tenant $tenant, Source $sheet, Source $coa, FormulaVersionService $versions, ProductionOrderService $orders, ProductionBatchService $batches): void
    {
        $koh = Substance::query()->firstOrCreate(['cas' => '1310-58-3'], ['inci' => 'POTASSIUM HYDROXIDE', 'name_en' => 'Potassium hydroxide']);
        $acid = TradeMaterial::factory()->create(['trade_name' => 'Demo Prod LABSA', 'function' => 'Anionic surfactant acid', 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);
        $base = TradeMaterial::factory()->for($koh)->create(['trade_name' => 'Demo Prod KOH', 'function' => 'Alkali', 'status' => TradeMaterialStatus::Active]);
        $water = TradeMaterial::factory()->create(['trade_name' => 'Demo Prod Neutral Water', 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);

        $value = fn (TradeMaterial $material, string $property, string $unit, string $amount, ?string $lot = null): PropertyValue => PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => $property, 'unit' => $unit, 'value' => $amount, 'lot_no' => $lot,
            'source_id' => $lot === null ? $sheet->id : $coa->id, 'confidence' => $lot === null ? Confidence::L2SupplierTds : Confidence::L1CoaBatch,
        ]);
        $value($acid, 'acid_value', 'mgKOH/g', '180');
        $value($base, 'purity', '%w/w', '90');
        $value($acid, 'acid_value', 'mgKOH/g', '190', 'DEMO-LOT-ACID');
        $value($base, 'purity', '%w/w', '80', 'DEMO-LOT-BASE');

        $formula = Formula::factory()->create(['tenant_id' => $tenant->id, 'code' => self::NEUTRAL_CODE, 'name' => 'Demo LABSA neutralisation', 'product_type' => 'detergent']);
        $version = $versions->createVersion($formula, [
            ['trade_material_id' => $acid->id, 'percent_ww' => '40'],
            ['trade_material_id' => $base->id, 'percent_ww' => '6'],
            ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
        $version->update(['status' => FormulaStatus::Approved]);

        $data = ['batch_size' => '2.5', 'batch_unit' => 'kg', 'scale_resolution_g' => '1', 'planned_on' => '2026-11-24'];
        foreach ([['DEMO-LOT-ACID', 'DEMO-LOT-BASE'], ['DEMO-LOT-NOCOA', 'DEMO-LOT-BASE']] as [$acidLot, $baseLot]) {
            $order = $this->approve($orders->create($version->refresh(), $data));
            $batch = $batches->start($order, 'Demo Operator', null);
            $this->weigh($batches, $batch, 'Demo Prod LABSA', '1000', $acidLot);
            $this->weigh($batches, $batch, 'Demo Prod KOH', '150', $baseLot);
        }
    }
}
