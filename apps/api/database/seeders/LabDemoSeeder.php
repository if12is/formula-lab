<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabProtocol;
use App\Models\LabTrial;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Formula\FormulaWorkflowService;
use App\Services\Lab\LabTrialService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo lab trials for the lab screens (phase O): the Playwright specs and the review screenshots use them. They hang on
 * the DEMO-SHAMPOO formula of FormulationDemoSeeder: version 1 (approved) has a completed trial that holds the required
 * measurements, version 2 has one trial still open with a single reading. The figures are placeholders for the screens, not
 * reference data. Skipped when the formula already has trials. Two more formulas show the workflow: DEMO-LAB-APPROVED, approved
 * and signed after a complete trial, and DEMO-LAB-BLOCKED, in lab testing with a trial that lacks two required measurements.
 * Run with `php artisan db:seed --class=LabDemoSeeder`.
 *
 * Loop 2 (O-03, O-04, O-05, O-07) adds two more formulas on two Demo Lab materials, with placeholder figures for the screens
 * only: DEMO-LAB-CURVE (salt-curve points at 0 % and 1 % on a completed trial, an open trial for more, and an active matter
 * reading to compare with the computed one) and DEMO-LAB-TESTED (approved, with a stability result, so its dose and required
 * HLB are recorded as tested). The activity and required-HLB values on the Demo Lab materials are demo data, not reference data.
 */
class LabDemoSeeder extends Seeder
{
    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'lab-demo-seeder';

    public const APPROVED_CODE = 'DEMO-LAB-APPROVED';

    public const BLOCKED_CODE = 'DEMO-LAB-BLOCKED';

    public function run(LabTrialService $trials, FormulaVersionService $versions, FormulaWorkflowService $workflow): void
    {
        $this->call(FormulationDemoSeeder::class);
        $this->call(LabProtocolSeeder::class);

        DB::transaction(function () use ($trials, $versions, $workflow): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $this->seed($trials, $versions, $workflow);
        });
    }

    private function seed(LabTrialService $trials, FormulaVersionService $versions, FormulaWorkflowService $workflow): void
    {
        $shampoo = Formula::query()->where('code', FormulationDemoSeeder::FORMULA_CODE)->firstOrFail();
        $ph = LabProtocol::query()->where('code', 'ph')->firstOrFail();
        $viscosity = LabProtocol::query()->where('code', 'viscosity_brookfield')->firstOrFail();

        if (! LabTrial::query()->whereIn('formula_version_id', $shampoo->versions()->reorder()->select('id'))->exists()) {
            $this->seedShampooTrials($trials, $shampoo, $ph, $viscosity);
        }

        if (! Formula::query()->where('code', self::APPROVED_CODE)->exists()) {
            $approved = $this->version($versions, self::APPROVED_CODE, 'Demo approved shampoo');
            $workflow->sendToLab($approved, null, 'Ready for the bench');
            $trial = $trials->start($approved, ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-12', 'operator' => 'Demo Chemist']);
            $trials->record($trial, ['property' => 'ph', 'value' => '5.7', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-12T10:00']);
            $trials->record($trial, ['property' => 'density', 'value' => '1.03', 'unit' => 'g/mL', 'method' => 'Demo bench method', 'instrument' => 'Demo pycnometer 25 mL', 'measured_at' => '2026-11-12T10:30']);
            $trials->record($trial, ['property' => 'viscosity', 'value' => '2900', 'unit' => 'mPa·s', 'lab_protocol_id' => $viscosity->id, 'instrument' => 'Demo Brookfield 2', 'measured_at' => '2026-11-12T11:00']);
            $trials->complete($trial);
            $workflow->approve($approved, null, 'Passed the bench');
        }

        $this->seedLoopTwo($trials, $versions, $workflow, $ph, $viscosity);

        if (! Formula::query()->where('code', self::BLOCKED_CODE)->exists()) {
            $blocked = $this->version($versions, self::BLOCKED_CODE, 'Demo blocked shampoo');
            $workflow->sendToLab($blocked);
            $partial = $trials->start($blocked, ['batch_size' => '300', 'batch_unit' => 'g', 'trial_date' => '2026-11-19', 'operator' => 'Demo Technician']);
            $trials->record($partial, ['property' => 'ph', 'value' => '6.4', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-19T09:00']);
            $trials->complete($partial);
        }
    }

    public const CURVE_CODE = 'DEMO-LAB-CURVE';

    public const TESTED_CODE = 'DEMO-LAB-TESTED';

    private function seedLoopTwo(LabTrialService $trials, FormulaVersionService $versions, FormulaWorkflowService $workflow, LabProtocol $ph, LabProtocol $viscosity): void
    {
        if (Formula::query()->whereIn('code', [self::CURVE_CODE, self::TESTED_CODE])->exists()) {
            return;
        }

        $coa = Source::query()->where('title', 'Demo batch certificate')->firstOrFail();
        $reference = Source::query()->where('title', 'Demo reference book')->firstOrFail();
        $surfactant = TradeMaterial::factory()->create(['trade_name' => 'Demo Lab Surfactant', 'grade' => '70%', 'function' => 'Surfactant', 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);
        $water = TradeMaterial::factory()->create(['trade_name' => 'Demo Lab Water', 'grade' => 'Demineralised', 'function' => 'Solvent', 'status' => TradeMaterialStatus::Active, 'substance_id' => null]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $surfactant->id, 'property' => 'active_matter', 'unit' => '%w/w', 'value' => '70', 'confidence' => Confidence::L1CoaBatch, 'source_id' => $coa->id, 'valid_from' => '2026-04-02']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $water->id, 'property' => 'active_matter', 'unit' => '%w/w', 'value' => '0', 'confidence' => Confidence::L5Reference, 'source_id' => $reference->id, 'valid_from' => '2026-01-15']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $surfactant->id, 'property' => 'required_hlb', 'unit' => 'HLB', 'value' => '11', 'confidence' => Confidence::L5Reference, 'source_id' => $reference->id, 'valid_from' => '2026-01-15']);

        // The salt curve: two points on a completed trial, and an open trial for the person to add more (and an active reading).
        $curve = $this->version($versions, self::CURVE_CODE, 'Demo salt-curve shampoo', $surfactant, $water);
        $closed = $trials->start($curve, ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-14', 'operator' => 'Demo Chemist']);
        foreach ([['0', '120'], ['1', '1150']] as [$salt, $value]) {
            $trials->record($closed, ['property' => 'viscosity', 'value' => $value, 'unit' => 'mPa·s', 'salt_pct' => $salt, 'lab_protocol_id' => $viscosity->id, 'instrument' => 'Demo Brookfield 2', 'measured_at' => '2026-11-14T10:'.($salt === '0' ? '00' : '30')]);
        }
        $trials->record($closed, ['property' => 'density', 'value' => '1.05', 'unit' => 'g/mL', 'method' => 'Demo bench method', 'instrument' => 'Demo pycnometer 25 mL', 'measured_at' => '2026-11-14T11:00']);
        $trials->complete($closed);
        $open = $trials->start($curve, ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-20', 'operator' => 'Demo Technician']);
        $trials->record($open, ['property' => 'active_matter', 'value' => '8.1', 'unit' => '%w/w', 'method' => 'Demo MBAS titration', 'instrument' => 'Demo burette', 'measured_at' => '2026-11-20T09:00']);

        // Approved, with a stability result: its dose and required HLB are recorded as tested.
        $tested = $this->version($versions, self::TESTED_CODE, 'Demo tested shampoo', $surfactant, $water);
        $workflow->sendToLab($tested, null, 'Ready for the bench');
        $stable = $trials->start($tested, ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-10', 'operator' => 'Demo Chemist']);
        $trials->record($stable, ['property' => 'ph', 'value' => '5.6', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-10T10:00']);
        $trials->record($stable, ['property' => 'density', 'value' => '1.04', 'unit' => 'g/mL', 'method' => 'Demo bench method', 'instrument' => 'Demo pycnometer 25 mL', 'measured_at' => '2026-11-10T10:30']);
        $trials->record($stable, ['property' => 'viscosity', 'value' => '3000', 'unit' => 'mPa·s', 'lab_protocol_id' => $viscosity->id, 'instrument' => 'Demo Brookfield 2', 'measured_at' => '2026-11-10T11:00']);
        $trials->record($stable, ['property' => 'stability_4w', 'value' => '97', 'unit' => '%', 'method' => 'Demo 4 weeks at 40 C', 'instrument' => 'Demo oven', 'measured_at' => '2026-12-08T10:00']);
        $trials->complete($stable);
        $workflow->approve($tested, null, 'Passed the bench and the stability test');
    }

    private function seedShampooTrials(LabTrialService $trials, Formula $formula, LabProtocol $ph, LabProtocol $viscosity): void
    {
        $versions = $formula->versions()->get()->keyBy('version');

        $first = $trials->start($versions[1], ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-10', 'operator' => 'Demo Chemist', 'notes' => 'Pilot batch on the bench']);
        $trials->record($first, ['property' => 'ph', 'value' => '5.8', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-10T10:00']);
        $trials->record($first, ['property' => 'ph', 'value' => '5.9', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-10T10:20', 'note' => 'Repeated after recalibration']);
        $trials->record($first, ['property' => 'density', 'value' => '1.04', 'unit' => 'g/mL', 'method' => 'Demo bench method', 'instrument' => 'Demo pycnometer 25 mL', 'measured_at' => '2026-11-10T10:40']);
        $trials->record($first, ['property' => 'viscosity', 'value' => '3200', 'unit' => 'mPa·s', 'lab_protocol_id' => $viscosity->id, 'instrument' => 'Demo Brookfield 2', 'measured_at' => '2026-11-10T11:10']);
        $trials->record($first, ['property' => 'foam', 'value' => '85', 'unit' => 'mm', 'method' => 'Demo shake test', 'instrument' => 'Demo graduated cylinder', 'measured_at' => '2026-11-10T11:30']);
        $trials->complete($first);

        $second = $trials->start($versions[2], ['batch_size' => '1', 'batch_unit' => 'kg', 'trial_date' => '2026-11-18', 'operator' => 'Demo Technician']);
        $trials->record($second, ['property' => 'ph', 'value' => '6.1', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-18T09:15']);
    }

    private function version(FormulaVersionService $versions, string $code, string $name, ?TradeMaterial $surfactant = null, ?TradeMaterial $water = null): FormulaVersion
    {
        $tenant = Formula::query()->where('code', FormulationDemoSeeder::FORMULA_CODE)->firstOrFail()->tenant_id;
        $formula = Formula::factory()->create(['tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'product_type' => 'shampoo']);
        $surfactant ??= TradeMaterial::query()->where('trade_name', 'Demo SLES')->firstOrFail();
        $water ??= TradeMaterial::query()->where('trade_name', 'Demo Water')->firstOrFail();

        return $versions->createVersion($formula, [
            ['trade_material_id' => $surfactant->id, 'percent_ww' => '12'],
            ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
    }
}
