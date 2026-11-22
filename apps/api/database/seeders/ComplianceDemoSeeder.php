<?php

namespace Database\Seeders;

use App\Actions\Compliance\ImportRuleSetAction;
use App\Actions\Compliance\PublishRuleSetAction;
use App\Enums\Confidence;
use App\Enums\TradeMaterialStatus;
use App\Models\ComplianceRule;
use App\Models\Formula;
use App\Models\IfraCertificate;
use App\Models\MaterialComposition;
use App\Models\PropertyValue;
use App\Models\RuleSet;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the compliance screens (S-06): the Playwright specs and the review screenshots use it. A published
 * rule set about a FICTIONAL substance (CAS "TEST-0001", limit 0.5 % w/w), materials with and without a composition,
 * and one formula per outcome (PASS, FAIL, UNKNOWN) plus a fine fragrance for the methanol draft and IFRA. Nothing here
 * is regulatory data: the substance, the limit and the certificate figures are placeholders for the screens. Every component
 * has a CAS number and an INCI name: a CAS rule cannot rule a substance out of a component that has no CAS, which the engine
 * then reports as UNKNOWN.
 * Skipped when already there (a published rule set cannot be rebuilt). Run with `php artisan db:seed --class=ComplianceDemoSeeder`.
 */
class ComplianceDemoSeeder extends Seeder
{
    public const PRODUCT_TYPE = 'demo_compliance';

    public const RULE_SET_CODE = 'demo-test-substance';

    private const LOCK_KEY = 'compliance-demo-seeder';

    public function run(ComplianceRuleSetSeeder $methanol, FormulaVersionService $versions, PublishRuleSetAction $publish, ImportRuleSetAction $import): void
    {
        $methanol->run($import);

        DB::transaction(function () use ($versions, $publish): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::LOCK_KEY]);
            $this->seed($versions, $publish);
        });
    }

    private function seed(FormulaVersionService $versions, PublishRuleSetAction $publish): void
    {
        if (RuleSet::query()->where('code', self::RULE_SET_CODE)->exists()) {
            return;
        }

        $tenant = Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
        $standard = Source::factory()->create(['kind' => 'standard', 'title' => 'Demo test standard', 'retrieved_at' => '2026-02-01']);
        $coa = Source::factory()->create(['kind' => 'coa', 'title' => 'Demo composition certificate', 'retrieved_at' => '2026-03-01']);

        $ruleSet = RuleSet::query()->create(['code' => self::RULE_SET_CODE, 'version' => '1.0.0', 'name' => 'Demo: limit of a test substance', 'scope_notes' => 'A fictional rule for the screens.', 'status' => 'draft']);
        ComplianceRule::query()->create([
            'rule_set_id' => $ruleSet->getKey(),
            'position' => 1,
            'active' => true,
            'message' => 'TEST-0001 must stay at or below its limit',
            'rule' => [
                'id' => 'demo-test-0001-max',
                'version' => '1.0.0',
                'scope' => ['productTypes' => [self::PRODUCT_TYPE]],
                'target' => ['substanceCas' => 'TEST-0001'],
                'operator' => '<=',
                'limit' => '0.5',
                'unit' => '%w/w',
                'source' => ['sourceId' => $standard->getKey(), 'title' => $standard->title, 'ref' => 'Demo clause 1'],
                'messageKey' => 'compliance.rule.custom',
            ],
        ]);
        $publish($ruleSet);

        $cleanser = $this->material('Zz Cmpl Cleanser', 'Surfactant', $coa, [['TEST-0001', 'TEST INGREDIENT', '10'], ['68585-34-2', 'SODIUM LAURETH SULFATE', '60'], ['7732-18-5', 'AQUA', '30']]);
        $water = $this->material('Zz Cmpl Water', 'Solvent', $coa, [['7732-18-5', 'AQUA', '100']]);
        $mystery = $this->material('Zz Cmpl Mystery Material', 'Additive', null, []);
        $ethanol = $this->material('Zz Cmpl Ethanol', 'Solvent', $coa, [['TEST-0002', 'ALCOHOL DENAT.', '100']]);
        $oil = $this->material('Zz Cmpl Fragrance Oil', 'Fragrance', $coa, [['TEST-0005', 'PARFUM', '100']], fragrance: true);
        // L-01: the limit is read from a certificate of the oil. No density, flash point or price is seeded: the fragrance tab shows them as missing.
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $oil->getKey(), 'certificate_number' => 'DEMO-CERT-1', 'ifra_amendment' => 'Demo amendment', 'issuer' => 'Demo lab', 'issued_on' => '2026-03-01', 'source_id' => $coa->getKey(), 'confidence' => Confidence::L1CoaBatch]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $oil->getKey(), 'property' => 'ifra_limit', 'category' => 'Demo category A', 'value' => '0.9', 'unit' => '%w/w', 'source_id' => $coa->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-03-01', 'ifra_certificate_id' => $certificate->getKey()]);

        $formula = fn (string $code, string $name, string $type, array $lines, ?string $ifra = null): Formula => tap(
            Formula::query()->create(['tenant_id' => $tenant->getKey(), 'code' => $code, 'name' => $name, 'product_type' => $type, 'ifra_category' => $ifra]),
            fn (Formula $created) => $versions->createVersion($created, $lines, null),
        );

        $formula('CMPL-PASS', 'Compliant shampoo (screens)', self::PRODUCT_TYPE, [
            ['trade_material_id' => $cleanser->getKey(), 'percent_ww' => '4'],
            ['trade_material_id' => $water->getKey(), 'percent_ww' => null, 'is_qs' => true],
        ]);
        $formula('CMPL-FAIL', 'Over-limit shampoo (screens)', self::PRODUCT_TYPE, [
            ['trade_material_id' => $cleanser->getKey(), 'percent_ww' => '6'],
            ['trade_material_id' => $water->getKey(), 'percent_ww' => null, 'is_qs' => true],
        ]);
        $formula('CMPL-UNKNOWN', 'Shampoo with an unknown material (screens)', self::PRODUCT_TYPE, [
            ['trade_material_id' => $cleanser->getKey(), 'percent_ww' => '4'],
            ['trade_material_id' => $mystery->getKey(), 'percent_ww' => '1'],
            ['trade_material_id' => $water->getKey(), 'percent_ww' => null, 'is_qs' => true],
        ]);
        $formula('CMPL-FRAGRANCE', 'Fine fragrance (screens)', 'fine_fragrance', [
            ['trade_material_id' => $oil->getKey(), 'percent_ww' => '0.5'],
            ['trade_material_id' => $ethanol->getKey(), 'percent_ww' => null, 'is_qs' => true],
        ], 'Demo category A');
    }

    /**
     * @param  list<array{0: string|null, 1: string|null, 2: string}>  $components  [cas, inci, % w/w]
     */
    private function material(string $name, string $function, ?Source $source, array $components, bool $fragrance = false): TradeMaterial
    {
        $material = TradeMaterial::factory()->mixture()->create([
            'trade_name' => $name, 'function' => $function, 'status' => TradeMaterialStatus::Active,
            'composition_complete' => $components !== [], 'is_fragrance' => $fragrance,
        ]);

        foreach ($components as [$cas, $inci, $pct]) {
            MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'cas' => $cas, 'inci' => $inci, 'pct_ww' => $pct, 'source_id' => $source?->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-03-01']);
        }

        return $material;
    }
}
