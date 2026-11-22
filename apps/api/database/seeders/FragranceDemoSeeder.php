<?php

namespace Database\Seeders;

use App\Enums\Confidence;
use App\Enums\TradeMaterialStatus;
use App\Models\Formula;
use App\Models\IfraCertificate;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Lab\LabTrialService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the fragrance screens (L-01, L-04): a fragrance oil with an IFRA certificate and one category limit but no
 * density, flash point or price (the fragrance tab shows those as missing), and a perfume formula whose open lab trial holds
 * the ageing, chilling and filtration losses. The figures are placeholders for the screens, not reference data.
 * Run with `php artisan db:seed --class=FragranceDemoSeeder`.
 */
class FragranceDemoSeeder extends Seeder
{
    public const OIL_NAME = 'Demo Fragrance Oil';

    public const FORMULA_CODE = 'DEMO-PERFUME';

    public const CERTIFICATE_NUMBER = 'DEMO-CERT-9';

    /** Serialises concurrent runs (parallel Playwright workers each seed first). */
    private const LOCK_KEY = 'fragrance-demo-seeder';

    public function run(LabTrialService $trials, FormulaVersionService $versions): void
    {
        $this->call(FormulationDemoSeeder::class);
        $this->call(LabProtocolSeeder::class);

        DB::transaction(function () use ($trials, $versions): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);

            if (Formula::query()->where('code', self::FORMULA_CODE)->exists()) {
                return;
            }

            $coa = Source::query()->where('title', 'Demo batch certificate')->firstOrFail();
            $tenant = Formula::query()->where('code', FormulationDemoSeeder::FORMULA_CODE)->firstOrFail()->tenant_id;
            $oil = TradeMaterial::factory()->create(['trade_name' => self::OIL_NAME, 'function' => 'Fragrance', 'status' => TradeMaterialStatus::Active, 'substance_id' => null, 'is_fragrance' => true]);
            $certificate = IfraCertificate::factory()->create(['trade_material_id' => $oil->getKey(), 'certificate_number' => self::CERTIFICATE_NUMBER, 'ifra_amendment' => 'Demo amendment', 'issuer' => 'Demo lab', 'issued_on' => '2026-03-01', 'source_id' => $coa->getKey(), 'confidence' => Confidence::L1CoaBatch]);
            PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $oil->getKey(), 'property' => 'ifra_limit', 'category' => 'Demo category A', 'value' => '12', 'unit' => '%w/w', 'source_id' => $coa->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-03-01', 'ifra_certificate_id' => $certificate->getKey()]);

            $water = TradeMaterial::query()->where('trade_name', 'Demo Water')->firstOrFail();
            $formula = Formula::factory()->create(['tenant_id' => $tenant, 'code' => self::FORMULA_CODE, 'name' => 'Demo perfume', 'product_type' => 'fine_fragrance']);
            $version = $versions->createVersion($formula, [
                ['trade_material_id' => $oil->getKey(), 'percent_ww' => '20'],
                ['trade_material_id' => $water->getKey(), 'percent_ww' => null, 'is_qs' => true],
            ], null);

            $trial = $trials->start($version, ['batch_size' => '1000', 'batch_unit' => 'g', 'trial_date' => '2026-11-18', 'operator' => 'Demo Perfumer']);
            foreach (['ageing_loss' => '2', 'chilling_loss' => '1', 'filtration_loss' => '3'] as $property => $value) {
                $trials->record($trial, ['property' => $property, 'value' => $value, 'unit' => '%w/w', 'method' => 'Demo weighing before and after the stage', 'instrument' => 'Demo balance 1', 'measured_at' => '2026-11-18T10:00']);
            }
        });
    }
}
