<?php

namespace Tests\Feature\Assistant;

use App\Enums\Confidence;
use App\Exceptions\Domain\AssistantException;
use App\Models\ExchangeRate;
use App\Models\Formula;
use App\Models\MaterialComposition;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\RuleSet;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Assistant\ToolContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

/**
 * P-02: each tool is a thin wrapper, so every number below is the engine's (a Node process runs for real) or a stored, sourced value.
 * Test-fixture figures: A 60 % at 10 EGP/kg and B 40 % at 25 EGP/kg cost 6 + 10 = 16 EGP per kg.
 */
class AssistantToolsTest extends TestCase
{
    use BuildsCostedFormulas, BuildsRules, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>}
     */
    private function tool(string $tool, array $input, ?ToolContext $context = null): array
    {
        return app(AssistantToolRegistry::class)->get($tool)($input, $context ?? new ToolContext);
    }

    public function test_the_registry_offers_the_seven_tools_with_strict_schemas(): void
    {
        $definitions = app(AssistantToolRegistry::class)->definitions();

        $this->assertSame(['get_material', 'calculate_formula', 'optimize_tier', 'check_compliance', 'convert_units', 'explain_result', 'extract_coa'], array_column($definitions, 'name'));
        foreach ($definitions as $definition) {
            $schema = $definition['input_schema'];
            $this->assertSame('object', $schema['type'], $definition['name']);
            $this->assertFalse($schema['additionalProperties'], $definition['name'].' must refuse unknown keys');
            $this->assertNotEmpty($definition['description']);
            foreach ($schema['properties'] as $property => $spec) {
                $this->assertTrue(isset($spec['type']), "{$definition['name']}.{$property} needs a type");
            }
            $this->assertSame([], array_diff($schema['required'] ?? [], array_keys($schema['properties'])));
        }
    }

    public function test_a_tool_refuses_a_key_its_schema_does_not_have(): void
    {
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('get_material', ['query' => 'a', 'hack' => 'x']), 'hack');
    }

    // get_material

    public function test_get_material_returns_properties_and_prices_with_source_and_confidence(): void
    {
        $this->costedProduct(Tenant::factory()->create()->id);
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $this->materialA->id, 'property' => 'density', 'value' => '1.03', 'unit' => 'g/mL',
            'source_id' => Source::factory()->create(['title' => 'Supplier TDS A'])->id, 'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-03-01',
        ]);

        $answer = $this->tool('get_material', ['query' => 'Material A']);

        $this->assertTrue($answer['ok']);
        $this->assertSame([], $answer['missing']);
        $this->assertSame(1, $answer['data']['count']);
        $material = $answer['data']['materials'][0];
        $this->assertSame($this->materialA->id, $material['id']);
        $this->assertSame(['density', '1.03', 'g/mL', 'Supplier TDS A', 'L2_supplier_tds', '2026-03-01'], [
            $material['properties'][0]['property'], $material['properties'][0]['value'], $material['properties'][0]['unit'],
            $material['properties'][0]['source'], $material['properties'][0]['confidence'], $material['properties'][0]['validFrom'],
        ]);
        $this->assertSame(['10', 'EGP', 'kg', '2026-11-01', 'INV-2026-0042', 'L1_coa_batch'], [
            $material['prices'][0]['amount'], $material['prices'][0]['currency'], $material['prices'][0]['perUnit'],
            $material['prices'][0]['quotedOn'], $material['prices'][0]['source'], $material['prices'][0]['confidence'],
        ]);
    }

    public function test_get_material_reports_a_material_with_no_price_as_missing_not_zero(): void
    {
        $material = TradeMaterial::factory()->create(['trade_name' => 'Unpriced']);

        $answer = $this->tool('get_material', ['materialId' => $material->id]);

        $this->assertFalse($answer['ok']);
        $this->assertSame([['materialId' => $material->id, 'property' => 'price', 'reason' => 'price.no_quote']], $answer['missing']);
        $this->assertSame('Unpriced', $answer['data']['materials'][0]['name']);
        $this->assertSame([], $answer['data']['materials'][0]['prices']);
    }

    public function test_get_material_says_when_nothing_matches_or_nothing_is_asked(): void
    {
        $this->assertSame('material.not_found', $this->tool('get_material', ['query' => 'nothing like this'])['missing'][0]['reason']);
        $this->assertSame('input.missing', $this->tool('get_material', [])['missing'][0]['reason']);
    }

    // calculate_formula

    public function test_calculate_formula_costs_a_saved_version_with_the_engine(): void
    {
        $formula = $this->costedProduct(Tenant::factory()->create()->id);

        $answer = $this->tool('calculate_formula', ['formulaVersionId' => $formula->versions()->firstOrFail()->id]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame('16', $answer['data']['total']['value']);
        $this->assertSame(['Material A', '60', '10', '6'], [
            $answer['data']['lines'][0]['material'], $answer['data']['lines'][0]['percentWW'], $answer['data']['lines'][0]['priceEgpPerKg'], $answer['data']['lines'][0]['contributionEgpPerKg'],
        ]);
        $this->assertSame(['INV-2026-0042', 'L1_coa_batch', '2026-11-01'], [
            $answer['data']['lines'][0]['quote']['source'], $answer['data']['lines'][0]['quote']['confidence'], $answer['data']['lines'][0]['quote']['quotedOn'],
        ]);
        $this->assertArrayNotHasKey('trace', $answer['data']['lines'][0]);
        $this->assertArrayHasKey('trace', $answer['data']['total'], 'the explain tree stays on the total');
    }

    public function test_calculate_formula_costs_what_if_lines_without_saving_anything(): void
    {
        $this->costedProduct(Tenant::factory()->create()->id);

        $answer = $this->tool('calculate_formula', ['lines' => [
            ['materialId' => $this->materialA->id, 'percentWW' => '50'],
            ['materialId' => $this->materialB->id, 'percentWW' => '50'],
        ]]);

        $this->assertTrue($answer['ok']);
        $this->assertSame('17.5', $answer['data']['total']['value']);
        $this->assertSame(1, Formula::query()->count());
    }

    public function test_calculate_formula_reports_a_material_with_no_price_as_missing(): void
    {
        $unpriced = TradeMaterial::factory()->create();

        $answer = $this->tool('calculate_formula', ['lines' => [['materialId' => $unpriced->id, 'percentWW' => '100']]]);

        $this->assertFalse($answer['ok']);
        $this->assertNotSame([], $answer['missing']);
        $this->assertSame($unpriced->id, $answer['missing'][0]['materialId']);
    }

    public function test_calculate_formula_wants_exactly_one_of_version_or_lines_and_known_ids(): void
    {
        $this->assertSame('input.exactly_one', $this->tool('calculate_formula', [])['missing'][0]['reason']);
        $this->assertSame('formula_version.not_found', $this->tool('calculate_formula', ['formulaVersionId' => '7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11'])['missing'][0]['reason']);
        $this->assertSame('material.not_found', $this->tool('calculate_formula', ['lines' => [['materialId' => '7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11', 'percentWW' => '10']]])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('calculate_formula', ['lines' => [['materialId' => 'x', 'percentWW' => '1e3']]]));
    }

    // check_compliance

    public function test_check_compliance_runs_the_engine_rules_over_a_version(): void
    {
        $cleanser = TradeMaterial::factory()->create(['trade_name' => 'Cleanser', 'composition_complete' => true]);
        $water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
        $formula = Formula::factory()->create(['product_type' => 'shampoo']);
        $version = app(FormulaVersionService::class)->createVersion($formula, [
            ['trade_material_id' => $cleanser->id, 'percent_ww' => '20'],
            ['trade_material_id' => $water->id, 'percent_ww' => '80'],
        ], null);
        MaterialComposition::factory()->create(['trade_material_id' => $cleanser->id, 'cas' => 'TEST-0001', 'pct_ww' => '2.5', 'source_id' => Source::factory()->create()->id]);
        MaterialComposition::factory()->create(['trade_material_id' => $water->id, 'cas' => '7732-18-5', 'pct_ww' => '100', 'source_id' => Source::factory()->create()->id]);
        $water->update(['composition_complete' => true]);
        $this->addRule(RuleSet::factory()->create(['code' => 'shampoo-set']), $this->rule(['limit' => '0.6']));

        $answer = $this->tool('check_compliance', ['formulaVersionId' => $version->id, 'market' => 'EG']);

        $this->assertSame('shampoo', $answer['data']['productType']);
        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame(['EG', 'PASS'], [$answer['data']['market'], $answer['data']['overall']]);
        $rule = $answer['data']['reports'][0]['rules'][0];
        $this->assertSame(['test-0001-limit', 'PASS', '0.5', '0.6', '%w/w'], [$rule['ruleId'], $rule['status'], $rule['value'], $rule['limit'], $rule['unit']]);
        $this->assertNotNull($rule['sourceId']);

        $this->addRule(RuleSet::query()->where('code', 'shampoo-set')->firstOrFail(), $this->rule(['id' => 'test-0001-strict', 'limit' => '0.4']), 2);
        $failing = $this->tool('check_compliance', ['formulaVersionId' => $version->id]);
        $this->assertSame('FAIL', $failing['data']['overall']);
    }

    public function test_check_compliance_makes_unknown_a_missing_item_not_a_pass(): void
    {
        $unlisted = TradeMaterial::factory()->create();
        $formula = Formula::factory()->create(['product_type' => 'shampoo']);
        $version = app(FormulaVersionService::class)->createVersion($formula, [['trade_material_id' => $unlisted->id, 'percent_ww' => '100']], null);
        $this->addRule(RuleSet::factory()->create(), $this->rule());

        $answer = $this->tool('check_compliance', ['formulaVersionId' => $version->id]);

        $this->assertSame('UNKNOWN', $answer['data']['overall']);
        $this->assertFalse($answer['ok']);
        $this->assertNotSame([], $answer['missing'], 'a material with no composition is reported, never read as 0 %');
    }

    public function test_check_compliance_needs_a_known_version(): void
    {
        $this->assertSame('formula_version.not_found', $this->tool('check_compliance', ['formulaVersionId' => '7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11'])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('check_compliance', ['formulaVersionId' => '7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11', 'market' => 'US']));
    }

    // convert_units

    public function test_convert_units_converts_inside_a_dimension_exactly(): void
    {
        $answer = $this->tool('convert_units', ['value' => '250', 'from' => 'mL', 'to' => 'L']);

        $this->assertTrue($answer['ok']);
        $this->assertSame(['0.25', 'L', false], [$answer['data']['value'], $answer['data']['unit'], $answer['data']['rounded']]);
    }

    public function test_convert_units_converts_alcohol_strength_by_the_oiml_table(): void
    {
        $answer = $this->tool('convert_units', ['value' => '96', 'from' => '%v/v', 'to' => '%w/w']);

        $this->assertTrue($answer['ok']);
        $this->assertSame('%w/w', $answer['data']['unit']);
        $this->assertSame('93.838407', $answer['data']['value']);
        $this->assertTrue($answer['data']['rounded']);
        $this->assertSame('oiml_r22', $answer['data']['basis']['kind']);
    }

    public function test_convert_units_needs_a_density_for_mass_and_volume_and_never_defaults_it(): void
    {
        $answer = $this->tool('convert_units', ['value' => '500', 'from' => 'mL', 'to' => 'g']);

        $this->assertFalse($answer['ok']);
        $this->assertSame('density', $answer['missing'][0]['property']);
    }

    public function test_convert_units_uses_the_sourced_density_of_a_material(): void
    {
        $this->costedProduct(Tenant::factory()->create()->id);
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $this->materialA->id, 'property' => 'density', 'value' => '1.25', 'unit' => 'g/mL',
            'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-03-01',
        ]);

        $answer = $this->tool('convert_units', ['value' => '200', 'from' => 'mL', 'to' => 'g', 'materialId' => $this->materialA->id]);

        $this->assertTrue($answer['ok']);
        $this->assertSame('250', $answer['data']['value']);
        $this->assertSame(['explicit_density', 'L2_supplier_tds'], [$answer['data']['basis']['kind'], $answer['data']['basis']['confidence']]);
    }

    public function test_convert_units_uses_a_density_the_user_gave_as_the_weakest_confidence(): void
    {
        $answer = $this->tool('convert_units', ['value' => '2', 'from' => 'kg', 'to' => 'L', 'densityGml' => '0.8']);

        $this->assertSame('2.5', $answer['data']['value']);
        $this->assertSame(['user_input', 'L7_estimate'], [$answer['data']['basis']['sourceId'], $answer['data']['basis']['confidence']]);
    }

    public function test_convert_units_converts_money_with_the_dated_rate_of_the_database(): void
    {
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '50.5', 'rate_date' => '2026-11-01']);

        $answer = $this->tool('convert_units', ['value' => '10', 'from' => 'USD', 'to' => 'EGP', 'fxDate' => '2026-11-10']);

        $this->assertTrue($answer['ok']);
        $this->assertSame('505', $answer['data']['value']);
        $this->assertSame(['fx', '50.5', '2026-11-01'], [$answer['data']['basis']['kind'], $answer['data']['basis']['rate'], $answer['data']['basis']['date']]);

        $back = $this->tool('convert_units', ['value' => '101', 'from' => 'EGP', 'to' => 'USD', 'fxDate' => '2026-11-10']);
        $this->assertSame('2', $back['data']['value']);
    }

    public function test_convert_units_money_needs_a_date_and_a_rate(): void
    {
        $this->assertSame('fxDate', $this->tool('convert_units', ['value' => '10', 'from' => 'USD', 'to' => 'EGP'])['missing'][0]['property']);
        $this->assertSame('fx.no_rate_on_or_before_date', $this->tool('convert_units', ['value' => '10', 'from' => 'USD', 'to' => 'EGP', 'fxDate' => '2026-11-10'])['missing'][0]['reason']);
    }

    public function test_convert_units_refuses_unknown_and_incompatible_units(): void
    {
        $this->assertSame('unit.unknown', $this->tool('convert_units', ['value' => '1', 'from' => 'stone', 'to' => 'kg'])['missing'][0]['reason']);
        $this->assertSame('unit.incompatible', $this->tool('convert_units', ['value' => '1', 'from' => 'kg', 'to' => '%w/w'])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('convert_units', ['value' => 'ten', 'from' => 'kg', 'to' => 'g']));
    }

    // optimize_tier

    public function test_optimize_tier_without_a_stored_profile_says_the_profile_is_missing(): void
    {
        $answer = $this->tool('optimize_tier', ['productType' => 'dish_liquid', 'tier' => 'economy']);

        $this->assertFalse($answer['ok']);
        $this->assertSame([['property' => 'tier_profile', 'reason' => 'optimizer.profile_not_defined']], $answer['missing']);
    }

    /** @return array<string, mixed> TEST-FIXTURE profile: only an active-matter minimum of 10 %. */
    private function fixtureProfile(): array
    {
        return [
            'productType' => 'test_product', 'tier' => 'economy', 'targetRetailPrice' => ['per' => 'kg', 'value' => null],
            'constraints' => ['totalActiveMinPct' => '10', 'totalActiveMaxPct' => null, 'functionBounds' => [], 'requiredMaterials' => [], 'forbiddenMaterials' => [], 'maxCostEGPperKg' => null, 'materialBounds' => [], 'measuredRequirements' => []],
            'sources' => ['totalActiveMinPct' => 'TEST-FIXTURE'],
        ];
    }

    private function pricedMaterial(string $name, string $price, string $active): TradeMaterial
    {
        $material = TradeMaterial::factory()->create(['trade_name' => $name]);
        Price::factory()->create(['trade_material_id' => $material->id, 'amount' => $price, 'confidence' => Confidence::L2SupplierTds]);
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'active_matter', 'value' => $active, 'unit' => '%w/w',
            'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-01-01',
        ]);

        return $material;
    }

    public function test_optimize_tier_returns_the_optimizers_draft_formula(): void
    {
        config(['assistant.tier_profiles.test_product.economy' => $this->fixtureProfile()]);
        // Hand case: A (100 EGP/kg, 50 % active) and water (1 EGP/kg, 0 %), active >= 10: A 20, water 80, cost 20.8.
        $a = $this->pricedMaterial('A', '100', '50');
        $water = $this->pricedMaterial('Water', '1', '0');

        $answer = $this->tool('optimize_tier', ['productType' => 'test_product', 'tier' => 'economy', 'availableMaterialIds' => [$a->id, $water->id]]);

        $this->assertTrue($answer['ok'], json_encode($answer));
        $this->assertTrue($answer['data']['draft']);
        $this->assertSame('optimal', $answer['data']['status']);
        $this->assertSame('20.8', $answer['data']['costPerKg']);
        $percents = array_column($answer['data']['formula'], 'percentWW', 'materialId');
        $this->assertSame(['20.000', '80.000'], [$percents[$a->id], $percents[$water->id]]);
        $this->assertSame('L2_supplier_tds', $answer['data']['formula'][0]['priceConfidence']);
    }

    public function test_optimize_tier_reports_missing_prices_and_active_matter_instead_of_defaulting(): void
    {
        config(['assistant.tier_profiles.test_product.economy' => $this->fixtureProfile()]);
        $bare = TradeMaterial::factory()->create();

        $answer = $this->tool('optimize_tier', ['productType' => 'test_product', 'tier' => 'economy', 'availableMaterialIds' => [$bare->id]]);

        $this->assertFalse($answer['ok']);
        $this->assertContains($bare->id, array_column($answer['missing'], 'materialId'));
        $this->assertSame([], $answer['data']['formula']);
    }

    public function test_optimize_tier_needs_the_materials_and_validates_the_tier(): void
    {
        config(['assistant.tier_profiles.test_product.economy' => $this->fixtureProfile()]);

        $this->assertSame('availableMaterialIds', $this->tool('optimize_tier', ['productType' => 'test_product', 'tier' => 'economy'])['missing'][0]['property']);
        $this->assertSame('material.not_found', $this->tool('optimize_tier', ['productType' => 'test_product', 'tier' => 'economy', 'availableMaterialIds' => ['7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11']])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('optimize_tier', ['productType' => 'x', 'tier' => 'gold']));
    }

    // explain_result

    public function test_explain_result_lists_sources_confidence_and_what_is_below_l2_from_an_earlier_answer(): void
    {
        $formula = $this->costedProduct(Tenant::factory()->create()->id);
        $context = new ToolContext;
        $context->record('t1', 'calculate_formula', $this->tool('calculate_formula', ['formulaVersionId' => $formula->versions()->firstOrFail()->id]));

        $answer = $this->tool('explain_result', ['tool' => 'calculate_formula'], $context);

        $this->assertTrue($answer['ok']);
        $this->assertSame('calculate_formula', $answer['data']['tool']);
        $this->assertSame('L2_supplier_tds', $answer['data']['weakestConfidence']);
        $this->assertNotSame([], $answer['data']['sources']);
        $this->assertSame([], $answer['data']['belowL2']);
        $this->assertContains('INV-2026-0042', array_map(fn (array $row): string => Source::query()->whereKey($row['sourceId'])->value('title'), $answer['data']['sources']));
    }

    public function test_explain_result_flags_a_value_weaker_than_l2(): void
    {
        $context = new ToolContext;
        $context->record('t1', 'get_material', ['ok' => true, 'data' => ['materials' => [['prices' => [['sourceId' => 'S-1', 'confidence' => 'L1_coa_batch'], ['sourceId' => 'S-2', 'confidence' => 'L6_community']]]]], 'missing' => [], 'warnings' => []]);

        $answer = $this->tool('explain_result', ['tool' => 'get_material'], $context);

        $this->assertSame('L6_community', $answer['data']['weakestConfidence']);
        $this->assertSame([['sourceId' => 'S-2', 'confidence' => 'L6_community']], $answer['data']['belowL2']);
    }

    public function test_explain_result_passes_on_what_was_missing_and_says_when_there_is_nothing_to_explain(): void
    {
        $context = new ToolContext;
        $context->record('t1', 'convert_units', ['ok' => false, 'data' => [], 'missing' => [['property' => 'density', 'reason' => 'x']], 'warnings' => ['w']]);

        $answer = $this->tool('explain_result', ['tool' => 'convert_units'], $context);
        $this->assertSame([['property' => 'density', 'reason' => 'x']], $answer['data']['missing']);
        $this->assertFalse($answer['data']['answerOk']);
        $this->assertNull($answer['data']['weakestConfidence']);

        $this->assertSame('explain.no_result_in_this_turn', $this->tool('explain_result', ['tool' => 'optimize_tier'])['missing'][0]['reason']);
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('explain_result', ['tool' => 'explain_result']));
    }
}
