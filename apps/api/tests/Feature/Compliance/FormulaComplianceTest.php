<?php

namespace Tests\Feature\Compliance;

use App\Actions\Compliance\PublishRuleSetAction;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\MaterialComposition;
use App\Models\PropertyValue;
use App\Models\RuleSet;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

/** What the formula editor's Compliance panel and the label page are handed (the engine itself runs in the browser). */
class FormulaComplianceTest extends TestCase
{
    use BuildsRules, RefreshDatabase;

    private Formula $formula;

    private FormulaVersion $version;

    private TradeMaterial $cleanser;

    private TradeMaterial $water;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanser = TradeMaterial::factory()->create(['trade_name' => 'Cleanser', 'composition_complete' => true]);
        $this->water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
        $this->formula = Formula::factory()->create(['code' => 'F-1', 'name' => 'Shampoo', 'product_type' => 'shampoo']);
        $this->version = app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => $this->cleanser->id, 'percent_ww' => '20'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
        MaterialComposition::factory()->create(['trade_material_id' => $this->cleanser->id, 'cas' => 'TEST-0001', 'pct_ww' => '2.5', 'source_id' => Source::factory()->create()->id]);
    }

    private function editor(): TestResponse
    {
        return $this->get(route('formulas.versions.edit', [$this->formula, 1]));
    }

    public function test_the_editor_gets_the_rule_sets_assembled_for_the_engine_with_their_product_types(): void
    {
        $ruleSet = RuleSet::factory()->create(['code' => 'shampoo-set', 'name' => 'Shampoo rules']);
        $this->addRule($ruleSet, $this->rule());
        $this->addRule($ruleSet, $this->rule(['id' => 'off']), 2)->update(['active' => false]);
        $this->addRule($ruleSet, $this->rule(['id' => 'worded', 'scope' => ['productTypes' => ['body_wash']]]), 3)->update(['message' => 'Custom wording']);

        $this->editor()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Editor')
            ->has('compliance.ruleSets', 1)
            ->where('compliance.ruleSets.0.code', 'shampoo-set')
            ->where('compliance.ruleSets.0.status', 'draft')
            ->where('compliance.ruleSets.0.productTypes', ['shampoo', 'body_wash'])
            ->where('compliance.ruleSets.0.assembled.id', 'shampoo-set')
            ->has('compliance.ruleSets.0.assembled.rules', 2)
            ->where('compliance.ruleSets.0.messages.worded', 'Custom wording')
            ->where('compliance.ruleSets.0.href', route('compliance.show', $ruleSet, false)));
    }

    public function test_a_published_rule_set_carries_the_hash_the_panel_checks_it_against(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $this->addRule($ruleSet, $this->rule());
        app(PublishRuleSetAction::class)($ruleSet);

        $this->editor()->assertInertia(fn (Assert $page) => $page
            ->where('compliance.ruleSets.0.status', 'published')
            ->where('compliance.ruleSets.0.hash', $ruleSet->fresh()->hash)
            ->where('compliance.ruleSets.0.assembled.status', 'published'));
    }

    public function test_the_editor_materials_carry_their_composition_for_the_engine(): void
    {
        $this->editor()->assertInertia(fn (Assert $page) => $page
            ->where("materials.{$this->cleanser->id}.composition.components.0.cas", 'TEST-0001')
            ->where("materials.{$this->cleanser->id}.composition.components.0.pctWW", '2.5')
            ->where("materials.{$this->cleanser->id}.composition.complete", true)
            ->where("materials.{$this->water->id}.composition", null));
    }

    public function test_the_editor_carries_the_ifra_category_saved_on_the_formula(): void
    {
        $this->formula->update(['ifra_category' => 'Cat X']);

        $this->editor()->assertInertia(fn (Assert $page) => $page->where('formula.ifra_category', 'Cat X'));
    }

    public function test_the_ifra_category_is_saved_on_the_formula_as_free_text_and_can_be_cleared(): void
    {
        $this->put("/formulas/{$this->formula->getKey()}/ifra-category", ['ifra_category' => '  Category 4  '])->assertSessionHas('success');
        $this->assertSame('Category 4', $this->formula->fresh()->ifra_category);

        $this->put("/formulas/{$this->formula->getKey()}/ifra-category", ['ifra_category' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->formula->fresh()->ifra_category);

        $this->put("/formulas/{$this->formula->getKey()}/ifra-category", ['ifra_category' => str_repeat('x', 51)])->assertSessionHasErrors('ifra_category');
    }

    public function test_the_label_page_gets_the_lines_the_compositions_and_the_rule_sets(): void
    {
        RuleSet::factory()->create(['code' => 'warnings']);

        $this->get(route('formulas.versions.label', [$this->formula, 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Label')
            ->where('formula.code', 'F-1')
            ->where('formula.product_type', 'shampoo')
            ->where('version.number', 1)
            ->where('version.status', 'draft')
            ->has('lines', 2)
            ->where('lines.0.trade_material_id', $this->cleanser->id)
            ->where('lines.0.percent_ww', '20')
            ->where('lines.1.is_qs', true)
            ->where('lines.1.percent_ww', '')
            ->where("materials.{$this->cleanser->id}.composition.components.0.cas", 'TEST-0001')
            ->has('ruleSets', 1));
    }

    public function test_the_label_page_opens_an_older_version_and_404s_for_an_unknown_one(): void
    {
        $this->get(route('formulas.versions.label', [$this->formula, 1]))->assertOk();
        $this->get("/formulas/{$this->formula->getKey()}/versions/9/label")->assertNotFound();
    }

    public function test_an_ifra_limit_value_reaches_the_editor_with_its_category(): void
    {
        $fragrance = TradeMaterial::factory()->create(['trade_name' => 'Oil', 'is_fragrance' => true]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $fragrance->id, 'property' => 'ifra_limit', 'category' => 'Cat X', 'value' => '0.9', 'unit' => '%w/w', 'confidence' => 'L1_coa_batch']);
        $version = app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => $fragrance->id, 'percent_ww' => '0.5'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ], 'Add fragrance');

        $this->get(route('formulas.versions.edit', [$this->formula, $version->version]))->assertInertia(fn (Assert $page) => $page
            ->where("materials.{$fragrance->id}.is_fragrance", true)
            ->where("materials.{$fragrance->id}.ifra_limits.0.category", 'Cat X')
            ->where("materials.{$fragrance->id}.ifra_limits.0.value", '0.9'));
    }
}
