<?php

namespace Tests\Feature\Assistant;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\TaxMode;
use App\Exceptions\Domain\AssistantException;
use App\Models\AssistantDisclosure;
use App\Models\Formula;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Support\Assistant\AssistantToolRegistry;
use App\Support\Assistant\NumberGuard;
use App\Support\Assistant\ToolContext;
use App\Support\DecimalCompare;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\Concerns\GrantsAssistantConsent;
use Tests\fakes\anthropic\FakeMessagesClient;
use Tests\TestCase;

/**
 * P-11: landed_cost, packaging_bom, price_waterfall, fragrance_blend and emulsion_phases. Each is a thin wrapper, so every number is the engine's
 * (a Node process runs for real). TEST-FIXTURE figures: A 60 % at 10 and B 40 % at 25 EGP/kg; a 2 + 1 EGP packaging BOM; a product costing 25
 * with 20/25/25 % margins and 2 EGP distribution shelves at 57 with 14 % VAT.
 */
class AssistantFormulaToolsTest extends TestCase
{
    use BuildsCostedFormulas, GrantsAssistantConsent, RefreshDatabase;

    private FakeMessagesClient $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = FakeMessagesClient::bind($this->app);
    }

    private const MISSING_ID = '7b0e3a52-9d63-4a64-9d1c-0b6f0c0a6d11';

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, data: array<string, mixed>, missing: list<array<string, mixed>>, warnings: list<string>}
     */
    private function tool(string $tool, array $input): array
    {
        return app(AssistantToolRegistry::class)->get($tool)($input, new ToolContext);
    }

    /** Every number of a tool answer must be one the guard accepts when the model quotes it. */
    private function assertGuardAccepts(string $reply, array $answer): void
    {
        $guard = app(NumberGuard::class);
        $this->assertSame([], $guard->violations($reply, $guard->collect([$answer], (array) config('assistant.guard.identifier_keys'))));
    }

    private function source(string $title = 'Supplier quote'): Source
    {
        return Source::factory()->create(['title' => $title, 'retrieved_at' => '2026-11-01']);
    }

    // schema strictness

    public function test_the_new_tools_are_offered_with_strict_schemas_the_provider_accepts(): void
    {
        $definitions = collect(app(AssistantToolRegistry::class)->definitions())->keyBy('name');
        $names = ['landed_cost', 'packaging_bom', 'price_waterfall', 'fragrance_blend', 'emulsion_phases'];

        $this->assertSame($names, array_slice($definitions->keys()->all(), -5));
        // Keywords a strict (structured outputs) tool refuses; the limits are checked in PHP instead.
        $refused = ['maxItems', 'minItems', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'format', 'default', 'oneOf', 'allOf', 'anyOf', 'not', '$ref', 'uniqueItems', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];
        foreach ($names as $name) {
            $schema = $definitions[$name]['input_schema'];
            $this->assertSame('object', $schema['type'], $name);
            $this->assertFalse($schema['additionalProperties'], $name);
            $this->assertSame([], array_diff($schema['required'] ?? [], array_keys($schema['properties'])), $name);
            $this->assertNotEmpty($definitions[$name]['description']);
            $this->assertNoKeyword($schema, $refused, $name);
        }
    }

    /** @param  array<string, mixed>  $node A schema node: its keys are keywords, except the names inside a `properties` map. */
    private function assertNoKeyword(array $node, array $refused, string $name): void
    {
        foreach ($node as $key => $child) {
            $this->assertNotContains($key, $refused, "{$name} uses `{$key}`, which a strict tool schema refuses");
            if (! is_array($child)) {
                continue;
            }
            if ($key === 'properties') {
                foreach ($child as $property) {
                    $this->assertNoKeyword($property, $refused, $name);
                }
            } elseif (! array_is_list($child)) {
                $this->assertNoKeyword($child, $refused, $name);
            }
        }
    }

    public function test_the_new_tools_refuse_unknown_keys_and_bad_values_in_php(): void
    {
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('landed_cost', ['materialIds' => [self::MISSING_ID], 'hack' => 'x']), 'hack');
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('landed_cost', ['materialIds' => array_fill(0, 11, self::MISSING_ID)]));
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('packaging_bom', ['packagingBomId' => 'x']));
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('price_waterfall', ['formulaId' => self::MISSING_ID, 'direction' => 'sideways']));
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('fragrance_blend', ['fragranceMaterialId' => self::MISSING_ID, 'fragranceOilPct' => '1e2', 'batchMassG' => '500']));
        $this->assertThrowsException(AssistantException::class, fn () => $this->tool('emulsion_phases', []));
    }

    public function test_the_data_each_tool_would_show_the_provider_is_declared(): void
    {
        $registry = app(AssistantToolRegistry::class);
        $declared = fn (string $name): array => array_column($registry->get($name)->dataCategories([]), 'value');

        $this->assertSame(['formula_cost'], $declared('landed_cost'));
        $this->assertSame(['formula_cost'], $declared('packaging_bom'));
        $this->assertSame(['formula_cost'], $declared('price_waterfall'));
        $this->assertSame(['formula_composition'], $declared('fragrance_blend'));
        $this->assertSame(['formula_composition'], $declared('emulsion_phases'));
    }

    // landed_cost

    public function test_landed_cost_gives_each_material_its_engine_price_with_the_quote_and_says_the_overlay_is_missing(): void
    {
        $this->costedProduct(Tenant::factory()->create()->id);

        $answer = $this->tool('landed_cost', ['materialIds' => [$this->materialA->id, $this->materialB->id, $this->materialA->id]]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertCount(2, $answer['data']['materials'], 'a repeated id is answered once');
        $this->assertSame(['Material A', '10', 'INV-2026-0042', 'L1_coa_batch', '2026-11-01'], [
            $answer['data']['materials'][0]['material'], $answer['data']['materials'][0]['priceEgpPerKg'],
            $answer['data']['materials'][0]['quote']['source'], $answer['data']['materials'][0]['quote']['confidence'], $answer['data']['materials'][0]['quote']['quotedOn'],
        ]);
        $this->assertSame('25', $answer['data']['materials'][1]['priceEgpPerKg']);
        $this->assertSame(['landed_cost.overlay_not_recorded'], $answer['warnings']);
        $this->assertGuardAccepts('الخامة A بـ 10 جنيه للكيلو والخامة B بـ 25.', $answer);
    }

    public function test_landed_cost_reports_a_material_with_no_price_as_missing_and_never_as_zero(): void
    {
        $this->costedProduct(Tenant::factory()->create()->id);
        $unpriced = TradeMaterial::factory()->create(['trade_name' => 'Unpriced']);

        $answer = $this->tool('landed_cost', ['materialIds' => [$this->materialA->id, $unpriced->id]]);

        $this->assertFalse($answer['ok']);
        $this->assertSame($unpriced->id, $answer['missing'][0]['materialId']);
        $this->assertSame('10', $answer['data']['materials'][0]['priceEgpPerKg']);
        $this->assertNull($answer['data']['materials'][1]['priceEgpPerKg']);
        $this->assertSame('material.not_found', $this->tool('landed_cost', ['materialIds' => [self::MISSING_ID]])['missing'][0]['reason']);
    }

    // packaging_bom

    /** A bottle at 3 EGP with 20 % reject and a label at 1 EGP: 3 / 0.8 + 1 = 4.75 EGP per unit. */
    private function bomWithBottleAndLabel(): PackagingBom
    {
        $source = $this->source();
        $bom = PackagingBom::factory()->create(['name' => 'Shampoo 400 ml', 'sku_code' => 'SH-400']);
        foreach ([[ItemKind::Container, 'Bottle', '3', '20'], [ItemKind::Label, 'Label', '1', '0']] as $position => [$kind, $name, $price, $reject]) {
            PackagingBomLine::factory()->create([
                'packaging_bom_id' => $bom->id, 'position' => $position, 'trade_material_id' => TradeMaterial::factory()->packaging($kind)->create(['trade_name' => $name])->id,
                'price_egp' => $price, 'qty_per_unit' => '1', 'reject_pct' => $reject, 'source_id' => $source->id, 'confidence' => Confidence::L2SupplierTds,
            ]);
        }

        return $bom;
    }

    public function test_packaging_bom_returns_the_engine_cost_per_unit(): void
    {
        $answer = $this->tool('packaging_bom', ['packagingBomId' => $this->bomWithBottleAndLabel()->id]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame(['SH-400', 'EGP'], [$answer['data']['sku'], $answer['data']['total']['unit']]);
        $this->assertSame(0, DecimalCompare::compare($answer['data']['total']['value'], '4.75'));
        $this->assertSame('Bottle', $answer['data']['lines'][0]['name']);
        $this->assertSame(0, DecimalCompare::compare($answer['data']['lines'][0]['perUnit']['value'], '3.75'));
        $this->assertGuardAccepts('تكلفة التغليف 4.75 جنيه للوحدة، والإزازة 3.75.', $answer);
    }

    public function test_packaging_bom_reports_a_figure_it_does_not_have_and_gives_no_total(): void
    {
        $bom = PackagingBom::factory()->create();
        PackagingBomLine::factory()->create(['packaging_bom_id' => $bom->id, 'trade_material_id' => TradeMaterial::factory()->packaging(ItemKind::Label)->create(['trade_name' => 'Bare label'])->id]);

        $answer = $this->tool('packaging_bom', ['packagingBomId' => $bom->id]);

        $this->assertFalse($answer['ok']);
        $this->assertArrayNotHasKey('total', $answer['data']);
        $this->assertEqualsCanonicalizing(['price', 'qtyPerUnit', 'rejectPct'], collect($answer['missing'])->pluck('property')->all());
        $this->assertArrayNotHasKey('subject', $answer['missing'][0], 'the model reads the gap, not the screen wording');
    }

    public function test_packaging_bom_says_when_it_has_no_lines_or_does_not_exist(): void
    {
        $this->assertSame('packaging_bom.no_lines', $this->tool('packaging_bom', ['packagingBomId' => PackagingBom::factory()->create()->id])['missing'][0]['reason']);
        $this->assertSame('packaging_bom.not_found', $this->tool('packaging_bom', ['packagingBomId' => self::MISSING_ID])['missing'][0]['reason']);
    }

    // price_waterfall

    private function product(): Formula
    {
        $this->seed(TaxRegimeSeeder::class);

        return Formula::factory()->create([
            'tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'code' => 'LAD-1', 'tax_mode' => TaxMode::Rate, 'tax_rate_pct' => '14',
            'unit_cost' => '25', 'unit_cost_source_id' => $this->source('Cost sheet')->id, 'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);
    }

    /** @var array<string, string> */
    private const RATES = ['marginBasis' => 'markup_on_cost', 'factoryMarginPct' => '20', 'distributionCostPerUnit' => '2', 'wholesalerMarginPct' => '25', 'retailerMarginPct' => '25'];

    public function test_price_waterfall_walks_cost_to_shelf_with_the_engine(): void
    {
        $answer = $this->tool('price_waterfall', ['formulaId' => $this->product()->id, 'direction' => 'forward', ...self::RATES]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame('57', $answer['data']['consumerPrice']['value']);
        $this->assertSame('7', $answer['data']['vatAmount']['value']);
        $this->assertSame(['30', '32', '40', '50'], array_map(fn (array $step): string => $step['traced']['value'], array_slice($answer['data']['steps'], 1, 4)));
        $this->assertGuardAccepts('سعر الرف 57 جنيه شامل 7 ضريبة، وسعر المصنع 30.', $answer);
    }

    public function test_price_waterfall_walks_a_shelf_price_back_to_the_maximum_cost(): void
    {
        $answer = $this->tool('price_waterfall', ['formulaId' => $this->product()->id, 'direction' => 'reverse', 'shelfPriceEGP' => '57', 'fillKg' => '0.5', 'nonFormulaCostPerUnit' => '5', ...self::RATES]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame(['25', '50', '40'], [$answer['data']['maxUnitCost'], $answer['data']['maxCostPerKg'], $answer['data']['maxFormulaCostPerKg']]);
        $this->assertGuardAccepts('أقصى تكلفة للوحدة 25 ولكل كيلو 50.', $answer);
    }

    public function test_price_waterfall_lists_what_the_user_did_not_give_and_prices_nothing(): void
    {
        $product = $this->product();

        $answer = $this->tool('price_waterfall', ['formulaId' => $product->id, 'direction' => 'forward', 'factoryMarginPct' => '20']);
        $this->assertFalse($answer['ok']);
        $this->assertArrayNotHasKey('consumerPrice', $answer['data']);
        $this->assertContains('waterfall.retailerMarginPct', array_column($answer['missing'], 'property'));
        $this->assertArrayNotHasKey('text', $answer['missing'][0]);

        $this->assertSame('shelfPriceEGP', $this->tool('price_waterfall', ['formulaId' => $product->id, 'direction' => 'reverse'])['missing'][0]['property']);
        $this->assertSame('formula.not_found', $this->tool('price_waterfall', ['formulaId' => self::MISSING_ID, 'direction' => 'forward'])['missing'][0]['reason']);
    }

    // fragrance_blend

    private function oilWithLimit(string $limit = '1.5', string $category = 'Cat 12'): TradeMaterial
    {
        $oil = TradeMaterial::factory()->create(['trade_name' => 'Oil']);
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $oil->id, 'property' => 'ifra_limit', 'category' => $category, 'value' => $limit, 'unit' => '%w/w',
            'source_id' => $this->source('IFRA cert')->id, 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-03-01',
        ]);

        return $oil;
    }

    public function test_fragrance_blend_splits_a_batch_and_checks_the_certificate_limit(): void
    {
        $oil = $this->oilWithLimit('12');

        $answer = $this->tool('fragrance_blend', ['fragranceMaterialId' => $oil->id, 'fragranceOilPct' => '10', 'otherPct' => '2', 'batchMassG' => '500', 'ifraCategory' => 'Cat 12']);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame(['10', '50', '88', '440', '500'], [
            $answer['data']['fragranceOil']['pct']['value'], $answer['data']['fragranceOil']['mass']['value'],
            $answer['data']['carrier']['pct']['value'], $answer['data']['carrier']['mass']['value'], $answer['data']['totalMass']['value'],
        ]);
        $this->assertSame(['PASS', '12', 'L1_coa_batch', '2'], [
            $answer['data']['ifra']['status'], $answer['data']['ifra']['limitPct']['value'], $answer['data']['ifra']['limitPct']['confidence'], $answer['data']['ifra']['marginPct']['value'],
        ]);
        $this->assertGuardAccepts('الزيت 10% يعني 50 جرام والكاريير 88% يعني 440 جرام، وهامش IFRA 2.', $answer);
    }

    public function test_fragrance_blend_over_the_limit_fails_and_without_a_limit_is_unknown_never_a_pass(): void
    {
        $oil = $this->oilWithLimit('1.5');

        $over = $this->tool('fragrance_blend', ['fragranceMaterialId' => $oil->id, 'fragranceOilPct' => '10', 'batchMassG' => '500', 'ifraCategory' => 'Cat 12']);
        $this->assertTrue($over['ok']);
        $this->assertSame('FAIL', $over['data']['ifra']['status']);

        foreach ([['ifraCategory' => 'Cat 99'], []] as $extra) {
            $unknown = $this->tool('fragrance_blend', ['fragranceMaterialId' => $oil->id, 'fragranceOilPct' => '10', 'batchMassG' => '500', ...$extra]);
            $this->assertFalse($unknown['ok']);
            $this->assertSame('UNKNOWN', $unknown['data']['ifra']['status']);
            $this->assertSame('ifraLimitPct', $unknown['missing'][0]['property']);
        }
    }

    public function test_fragrance_blend_reports_percents_over_100_and_an_unknown_oil(): void
    {
        $oil = $this->oilWithLimit();

        $answer = $this->tool('fragrance_blend', ['fragranceMaterialId' => $oil->id, 'fragranceOilPct' => '60', 'otherPct' => '40', 'batchMassG' => '500']);
        $this->assertFalse($answer['ok']);
        $this->assertSame([], $answer['data']);
        $this->assertSame('fragranceOilPct', $answer['missing'][0]['property']);

        $this->assertSame('material.not_found', $this->tool('fragrance_blend', ['fragranceMaterialId' => self::MISSING_ID, 'fragranceOilPct' => '1', 'batchMassG' => '1'])['missing'][0]['reason']);
    }

    // emulsion_phases

    /** @param  list<array{0: TradeMaterial, 1: string|null, 2: string|null, 3?: bool}>  $lines Material, percent, phase, q.s. */
    private function phasedVersion(array $lines): string
    {
        $formula = Formula::factory()->create();
        $rows = array_map(fn (array $line): array => [
            'trade_material_id' => $line[0]->id, 'percent_ww' => $line[1], 'phase' => $line[2], 'is_qs' => $line[3] ?? false,
        ], $lines);

        return app(FormulaVersionService::class)->createVersion($formula, $rows, null)->id;
    }

    public function test_emulsion_phases_sums_each_phase_with_the_engine_and_balances_the_qs_line(): void
    {
        [$water, $oil, $cool] = [TradeMaterial::factory()->create(), TradeMaterial::factory()->create(), TradeMaterial::factory()->create()];
        $id = $this->phasedVersion([[$water, null, 'water', true], [$oil, '25', 'oil'], [$cool, '5', 'cool_down']]);

        $answer = $this->tool('emulsion_phases', ['formulaVersionId' => $id]);

        $this->assertTrue($answer['ok'], json_encode($answer['missing']));
        $this->assertSame([['water', '70'], ['oil', '25'], ['cool_down', '5']], array_map(fn (array $total): array => [$total['phase'], $total['sum']], $answer['data']['totals']));
        $this->assertSame(['100', '0', true], [$answer['data']['sum'], $answer['data']['deviation'], $answer['data']['withinTolerance']]);
        $this->assertGuardAccepts('مرحلة المية 70% والزيت 25% والتبريد 5%.', $answer);
    }

    public function test_emulsion_phases_reports_a_formula_with_no_phase_or_a_line_without_one(): void
    {
        [$a, $b] = [TradeMaterial::factory()->create(), TradeMaterial::factory()->create()];

        $none = $this->tool('emulsion_phases', ['formulaVersionId' => $this->phasedVersion([[$a, '60', null], [$b, '40', null]])]);
        $this->assertFalse($none['ok']);
        $this->assertSame([['property' => 'phase', 'reason' => 'formula.phase_missing']], $none['missing']);

        $partial = $this->tool('emulsion_phases', ['formulaVersionId' => $this->phasedVersion([[$a, '60', 'water'], [$b, '40', null]])]);
        $this->assertFalse($partial['ok']);
        $this->assertSame([$b->id, 'phase', 'formula.phase_missing'], [$partial['missing'][0]['materialId'], $partial['missing'][0]['property'], $partial['missing'][0]['reason']]);

        $this->assertSame('formula_version.not_found', $this->tool('emulsion_phases', ['formulaVersionId' => self::MISSING_ID])['missing'][0]['reason']);
    }

    // consent gating (P-10) through the real endpoint with the scripted provider

    /** @return list<array{0: string, 1: array<string, mixed>, 2: list<string>}> */
    public static function gatedCalls(): array
    {
        return [
            'landed_cost' => ['landed_cost', ['materialIds' => [self::MISSING_ID]], ['formula_cost']],
            'packaging_bom' => ['packaging_bom', ['packagingBomId' => self::MISSING_ID], ['formula_cost']],
            'price_waterfall' => ['price_waterfall', ['formulaId' => self::MISSING_ID, 'direction' => 'forward'], ['formula_cost']],
            'fragrance_blend' => ['fragrance_blend', ['fragranceMaterialId' => self::MISSING_ID, 'fragranceOilPct' => '1', 'batchMassG' => '1'], ['formula_composition']],
            'emulsion_phases' => ['emulsion_phases', ['formulaVersionId' => self::MISSING_ID], ['formula_composition']],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $categories
     */
    #[DataProvider('gatedCalls')]
    public function test_without_consent_the_tool_is_refused_before_it_runs_and_with_it_the_call_is_logged(string $tool, array $input, array $categories): void
    {
        $this->runUnderConsentSession('p11-session');

        $this->model->callTool($tool, $input)->replyText('محتاج موافقتك.');
        $this->postJson('/api/assistant/ask', ['question' => 'سؤال عن 1 و 2', 'history' => []])->assertOk();
        $refused = $this->model->lastToolResults()[0];
        $this->assertTrue($refused['is_error']);
        $this->assertStringContainsString('بموافقتك', $refused['content']);
        $log = AssistantDisclosure::query()->sole();
        $this->assertSame([AssistantDisclosureOutcome::Refused, $tool], [$log->outcome, $log->surface]);
        $this->assertSame($categories, $log->categories);

        $this->consentTo();
        $this->model->callTool($tool, $input)->replyText('تمام.');
        $this->postJson('/api/assistant/ask', ['question' => 'سؤال عن 1 و 2', 'history' => []])->assertOk();
        $this->assertFalse($this->model->lastToolResults()[0]['is_error'] ?? false);
        $this->assertSame(1, AssistantDisclosure::query()->where('outcome', AssistantDisclosureOutcome::Sent)->where('surface', $tool)->count());
    }

    public function test_a_consent_for_the_wrong_category_does_not_open_the_tool(): void
    {
        $this->runUnderConsentSession('p11-session');
        $this->consentTo([AssistantDataCategory::FormulaComposition]);

        $this->model->callTool('packaging_bom', ['packagingBomId' => self::MISSING_ID])->replyText('محتاج موافقة التكاليف.');
        $this->postJson('/api/assistant/ask', ['question' => 'سؤال عن 1 و 2', 'history' => []])->assertOk();

        $this->assertTrue($this->model->lastToolResults()[0]['is_error']);
    }
}
