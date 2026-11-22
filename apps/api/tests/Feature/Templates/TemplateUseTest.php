<?php

namespace Tests\Feature\Templates;

use App\Actions\Template\CheckTemplateUseAction;
use App\Models\Formula;
use App\Models\FormulaTemplate;
use App\Models\MaterialComposition;
use App\Models\RuleSet;
use App\Models\TradeMaterial;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

/**
 * K-09: a template becomes a formula only after the engine validated it and the compliance engine had no FAIL. The engine
 * really runs here (a Node process), so every number is its answer: 12 + 1.5 + 0.2 + 0.5 = 14.2, so the q.s. water is 85.8.
 */
class TemplateUseTest extends TestCase
{
    use BuildsRules, RefreshDatabase;

    /** @var array<string, TradeMaterial> */
    private array $materials = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductFamilySeeder::class);

        foreach (['water', 'surfactant', 'salt', 'acid', 'preservative', 'perfume'] as $name) {
            $this->materials[$name] = TradeMaterial::factory()->create(['trade_name' => "Test {$name}", 'composition_complete' => true]);
        }
    }

    /** @return array<string, array<string, string|null>> */
    private function fill(): array
    {
        return [
            'water' => ['material_id' => $this->materials['water']->id],
            'anionic_surfactant' => ['material_id' => $this->materials['surfactant']->id, 'percent_ww' => '12'],
            'electrolyte' => ['material_id' => $this->materials['salt']->id, 'percent_ww' => '1.5'],
            'ph_adjuster' => ['material_id' => $this->materials['acid']->id, 'percent_ww' => '0.2'],
            'preservative' => ['material_id' => $this->materials['preservative']->id, 'percent_ww' => '0.5'],
        ];
    }

    /** @param  array<string, mixed>  $override */
    private function use(array $override = [])
    {
        return $this->post(route('templates.use', 'dish_liquid_basic'), ['code' => 'DISH-1', 'name' => 'My dish liquid', 'fill' => $this->fill(), ...$override]);
    }

    public function test_a_filled_template_becomes_a_draft_formula_linked_to_its_family_and_template_with_the_water_balanced(): void
    {
        $response = $this->use();

        $formula = Formula::query()->where('code', 'DISH-1')->sole();
        $response->assertRedirect(route('formulas.versions.edit', [$formula, 1]));

        $this->assertSame('dish_liquid', $formula->product_type);
        $this->assertSame('dish_liquid', $formula->productFamily->code);
        $this->assertSame('dish_liquid_basic', $formula->formulaTemplate->code);

        $lines = $formula->versions->sole()->lines()->orderBy('line_no')->get();
        $this->assertSame(['Test water', 'Test surfactant', 'Test salt', 'Test acid', 'Test preservative'], $lines->map(fn ($line) => $line->tradeMaterial->trade_name)->all());
        // The q.s. line carries the engine's balance (100 - 14.2 = 85.8), as the editor saves it.
        $this->assertTrue($lines[0]->is_qs);
        $this->assertSame(['85.8', '12', '1.5', '0.2', '0.5'], $lines->map(fn ($line) => rtrim(rtrim((string) $line->percent_ww, '0'), '.'))->all());
    }

    public function test_the_engine_balances_the_water_to_85_8_and_the_sum_to_100(): void
    {
        $report = app(CheckTemplateUseAction::class)(FormulaTemplate::query()->where('code', 'dish_liquid_basic')->sole(), $this->fill());

        $this->assertSame(['85.8', '12', '1.5', '0.2', '0.5'], array_column($report['instance']['lines'], 'percentWW'));
        $this->assertSame('100', $report['instance']['sum']);
        $this->assertSame('0', $report['instance']['deviation']);
        $this->assertSame('NOT_APPLICABLE', $report['overall']);
    }

    public function test_an_optional_line_with_a_material_is_kept_and_balances_the_water(): void
    {
        $this->use(['fill' => [...$this->fill(), 'fragrance' => ['material_id' => $this->materials['perfume']->id, 'percent_ww' => '0.3']]])->assertRedirect();

        $this->assertSame(6, Formula::query()->where('code', 'DISH-1')->sole()->versions->sole()->lines()->count());
    }

    public function test_a_missing_material_and_a_missing_share_are_reported_on_their_own_lines_and_nothing_is_created(): void
    {
        $fill = $this->fill();
        unset($fill['water'], $fill['electrolyte']['percent_ww']);

        $this->use(['fill' => $fill])->assertSessionHasErrors([
            'fill.water.material_id' => __('families.reasons.template_line_material_missing'),
            'fill.electrolyte.percent_ww' => __('families.reasons.template_line_percent_missing'),
        ]);

        $this->assertSame(0, Formula::query()->count());
    }

    public function test_an_empty_fill_is_refused_with_every_required_line_named(): void
    {
        $this->use(['fill' => []])->assertSessionHasErrors(['fill.water.material_id', 'fill.anionic_surfactant.material_id', 'fill.electrolyte.material_id', 'fill.ph_adjuster.material_id', 'fill.preservative.material_id']);
    }

    public function test_shares_that_leave_nothing_for_the_balancing_line_are_refused_by_the_engine(): void
    {
        $fill = $this->fill();
        $fill['anionic_surfactant']['percent_ww'] = '100';

        $this->use(['fill' => $fill])->assertSessionHasErrors(['template' => __('engine.formula.qs_balance_not_positive')]);
        $this->assertSame(0, Formula::query()->count());
    }

    public function test_a_line_that_is_not_in_the_template_is_refused(): void
    {
        $this->use(['fill' => [...$this->fill(), 'ghost' => ['material_id' => $this->materials['perfume']->id]]])
            ->assertSessionHasErrors(['fill.ghost.material_id' => __('families.reasons.template_fill_line_unknown')]);
    }

    public function test_the_same_material_on_two_lines_is_refused(): void
    {
        $fill = $this->fill();
        $fill['electrolyte']['material_id'] = $this->materials['salt']->id;
        $fill['ph_adjuster']['material_id'] = $this->materials['salt']->id;

        $this->use(['fill' => $fill])->assertSessionHasErrors(['template' => __('engine.formula.duplicate_material')]);
    }

    private function limitRule(string $limit): void
    {
        $ruleSet = RuleSet::factory()->create(['code' => 'dish-set', 'name' => 'Dish rules']);
        $this->addRule($ruleSet, $this->rule(['scope' => ['productTypes' => ['dish_liquid']], 'limit' => $limit]));
        MaterialComposition::factory()->create(['trade_material_id' => $this->materials['surfactant']->id, 'cas' => 'TEST-0001', 'pct_ww' => '10']);
    }

    public function test_a_compliance_fail_blocks_the_use_and_creates_nothing(): void
    {
        // 12 % of a material holding 10 % TEST-0001 = 1.2 %, above the 0.5 % limit.
        $this->limitRule('0.5');

        $this->use()->assertSessionHasErrors(['template' => __('families.reasons.template_compliance_failed')]);
        $this->assertSame(0, Formula::query()->count());
    }

    public function test_a_compliance_pass_lets_the_use_through(): void
    {
        $this->limitRule('5');

        $this->use()->assertRedirect();
        $this->assertSame(1, Formula::query()->count());
    }

    public function test_a_rule_set_of_another_product_type_does_not_judge_the_template(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $this->addRule($ruleSet, $this->rule(['scope' => ['productTypes' => ['shampoo']], 'limit' => '0.01']));
        MaterialComposition::factory()->create(['trade_material_id' => $this->materials['surfactant']->id, 'cas' => 'TEST-0001', 'pct_ww' => '10']);

        $this->use()->assertRedirect();
    }

    public function test_the_formula_code_must_be_free_and_the_name_given(): void
    {
        $this->use()->assertRedirect();
        $this->use()->assertSessionHasErrors('code');
        $this->use(['code' => 'DISH-2', 'name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_share_is_text_never_a_float(): void
    {
        $fill = $this->fill();
        $fill['anionic_surfactant']['percent_ww'] = '1e1';

        $this->use(['fill' => $fill])->assertSessionHasErrors('fill.anionic_surfactant.percent_ww');
    }
}
