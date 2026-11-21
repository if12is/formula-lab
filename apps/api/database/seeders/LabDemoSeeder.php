<?php

namespace Database\Seeders;

use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabProtocol;
use App\Models\LabTrial;
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

        if (! Formula::query()->where('code', self::BLOCKED_CODE)->exists()) {
            $blocked = $this->version($versions, self::BLOCKED_CODE, 'Demo blocked shampoo');
            $workflow->sendToLab($blocked);
            $partial = $trials->start($blocked, ['batch_size' => '300', 'batch_unit' => 'g', 'trial_date' => '2026-11-19', 'operator' => 'Demo Technician']);
            $trials->record($partial, ['property' => 'ph', 'value' => '6.4', 'unit' => 'pH', 'lab_protocol_id' => $ph->id, 'instrument' => 'Demo pH meter 1', 'measured_at' => '2026-11-19T09:00']);
            $trials->complete($partial);
        }
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

    private function version(FormulaVersionService $versions, string $code, string $name): FormulaVersion
    {
        $tenant = Formula::query()->where('code', FormulationDemoSeeder::FORMULA_CODE)->firstOrFail()->tenant_id;
        $formula = Formula::factory()->create(['tenant_id' => $tenant, 'code' => $code, 'name' => $name, 'product_type' => 'shampoo']);

        return $versions->createVersion($formula, [
            ['trade_material_id' => TradeMaterial::query()->where('trade_name', 'Demo SLES')->firstOrFail()->id, 'percent_ww' => '12'],
            ['trade_material_id' => TradeMaterial::query()->where('trade_name', 'Demo Water')->firstOrFail()->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
    }
}
