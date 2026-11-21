<?php

namespace Tests\Feature\Compliance;

use App\Enums\RuleSetStatus;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

class RuleSetCrudTest extends TestCase
{
    use BuildsRules, RefreshDatabase;

    public function test_the_compliance_page_is_no_longer_a_placeholder(): void
    {
        $this->assertNotContains('compliance', config('ui.placeholder_sections'));

        $this->get('/compliance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Compliance/Index')
            ->has('list.schema.filters', 3)
            ->where('list.schema.filters.0.key', 'status')
            ->where('list.schema.filters.1.key', 'product_type')
            ->where('list.schema.filters.1.optionsUrl', route('compliance.product-types', [], false))
            ->where('urls.sources', route('sources.search', [], false)));
    }

    public function test_it_creates_a_draft_rule_set_and_opens_it(): void
    {
        $source = Source::factory()->create();

        $response = $this->post('/compliance', ['code' => 'demo-set', 'version' => '1.0.0', 'name' => 'Demo set', 'scope_notes' => 'Notes', 'source_id' => $source->getKey()]);

        $ruleSet = RuleSet::query()->where('code', 'demo-set')->firstOrFail();
        $response->assertRedirect(route('compliance.show', $ruleSet))->assertSessionHas('success');
        $this->assertSame(RuleSetStatus::Draft, $ruleSet->status);
        $this->assertNull($ruleSet->hash);
        $this->assertSame($source->getKey(), $ruleSet->source_id);
    }

    public function test_it_validates_the_rule_set_fields(): void
    {
        $this->post('/compliance', ['code' => 'has space', 'version' => '', 'name' => ''])
            ->assertSessionHasErrors(['code', 'version', 'name']);
        $this->post('/compliance', ['code' => 'ok', 'version' => '1', 'name' => 'x', 'source_id' => 'not-a-uuid'])
            ->assertSessionHasErrors('source_id');

        $this->assertSame(0, RuleSet::query()->count());
    }

    public function test_a_code_cannot_repeat_a_version(): void
    {
        RuleSet::factory()->create(['code' => 'dup', 'version' => '1.0.0']);

        $this->post('/compliance', ['code' => 'dup', 'version' => '1.0.0', 'name' => 'Again'])->assertSessionHasErrors('version');
        $this->post('/compliance', ['code' => 'dup', 'version' => '1.1.0', 'name' => 'Next'])->assertSessionHasNoErrors();
    }

    public function test_it_edits_and_deletes_a_draft_with_its_rules(): void
    {
        $ruleSet = RuleSet::factory()->create(['code' => 'edit-me', 'version' => '1', 'name' => 'Before']);
        $this->addRule($ruleSet, $this->rule());

        $this->put("/compliance/{$ruleSet->getKey()}", ['code' => 'edit-me', 'version' => '2', 'name' => 'After'])->assertSessionHasNoErrors();
        $this->assertSame(['After', '2'], [$ruleSet->fresh()->name, $ruleSet->fresh()->version]);

        $this->delete("/compliance/{$ruleSet->getKey()}")->assertRedirect(route('compliance.index'));
        $this->assertSame(0, RuleSet::query()->count());
        $this->assertSame(0, ComplianceRule::query()->count());
    }

    public function test_the_list_filters_by_status_scope_product_type_and_search(): void
    {
        $shampoo = RuleSet::factory()->create(['code' => 'shampoo-set', 'name' => 'Shampoo rules']);
        $this->addRule($shampoo, $this->rule(['scope' => ['productTypes' => ['shampoo', 'body_wash']]]));
        $fragrance = RuleSet::factory()->create(['code' => 'fragrance-set', 'name' => 'Fragrance rules', 'status' => RuleSetStatus::Draft]);
        $this->addRule($fragrance, $this->rule(['id' => 'f1', 'scope' => ['productTypes' => ['fine_fragrance']]]));
        $empty = RuleSet::factory()->create(['code' => 'empty-set', 'name' => 'Nothing yet']);

        $codes = fn (string $query): array => collect($this->get("/compliance?{$query}")->viewData('page')['props']['list']['rows'])->pluck('code')->sort()->values()->all();

        $this->assertSame(['empty-set', 'fragrance-set', 'shampoo-set'], $codes(''));
        $this->assertSame(['shampoo-set'], $codes('product_type=body_wash'));
        $this->assertSame(['fragrance-set'], $codes('product_type=fine_fragrance'));
        $this->assertSame([], $codes('product_type=unknown_type'));
        $this->assertSame(['fragrance-set'], $codes('search=fragrance'));
        $this->assertSame(['empty-set', 'fragrance-set', 'shampoo-set'], $codes('status=draft'));
        $this->assertSame([], $codes('status=published'));
        $this->assertSame(['shampoo-set'], $codes('search=shampoo&status=draft&product_type=shampoo'));
        $this->assertSame($shampoo->getKey(), $this->get('/compliance?search=shampoo')->viewData('page')['props']['list']['rows'][0]['id']);
        $this->assertSame(['body_wash', 'shampoo'], $this->get('/compliance?search=shampoo')->viewData('page')['props']['list']['rows'][0]['product_types']);
        $this->assertSame(0, $empty->rules()->count());
    }

    public function test_the_list_sorts_and_pages(): void
    {
        foreach (['b', 'a', 'c'] as $code) {
            RuleSet::factory()->create(['code' => $code]);
        }

        $page = $this->get('/compliance?sort=code&dir=asc&per=10')->viewData('page')['props']['list'];

        $this->assertSame(['a', 'b', 'c'], array_column($page['rows'], 'code'));
        $this->assertSame(3, $page['meta']['total']);
    }

    public function test_the_product_type_options_list_every_scope_once(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $this->addRule($ruleSet, $this->rule(['scope' => ['productTypes' => ['b_type', 'a_type']]]), 1);
        $this->addRule($ruleSet, $this->rule(['id' => 'other', 'scope' => ['productTypes' => ['a_type']]]), 2);

        $this->getJson('/compliance/product-types')->assertOk()->assertExactJson([
            ['value' => 'a_type', 'label' => 'a_type'],
            ['value' => 'b_type', 'label' => 'b_type'],
        ]);
    }

    public function test_the_detail_page_lists_the_rules_with_source_operator_limit_group_and_message(): void
    {
        $ruleSet = RuleSet::factory()->create(['code' => 'detail-set']);
        $this->addRule($ruleSet, $this->rule(['id' => 'sum-a', 'group' => 'annex-sum']), 1);
        $this->addRule($ruleSet, $this->rule(['id' => 'ban', 'operator' => 'forbidden', 'limit' => null, 'source' => null, 'pendingSource' => true]), 2)->update(['message' => 'No TEST-0001 at all']);

        $this->get("/compliance/{$ruleSet->getKey()}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Compliance/Show')
            ->where('ruleSet.code', 'detail-set')
            ->where('ruleSet.status', 'draft')
            ->where('list.meta.total', 2)
            ->where('list.rows.0.rule_key', 'sum-a')
            ->where('list.rows.0.group', 'annex-sum')
            ->where('list.rows.0.limit', '0.5')
            ->where('list.rows.0.unit', '%w/w')
            ->where('list.rows.0.operator', '<=')
            ->where('list.rows.0.target_type', 'substanceCas')
            ->where('list.rows.0.target_value', 'TEST-0001')
            ->where('list.rows.0.source.title', 'Test standard')
            ->where('list.rows.0.source.ref', 'Clause 1')
            ->where('list.rows.1.operator', 'forbidden')
            ->where('list.rows.1.pending_source', true)
            ->where('list.rows.1.source', null)
            ->where('list.rows.1.message', 'No TEST-0001 at all')
            ->where('assembled.id', 'detail-set')
            ->where('assembled.status', 'draft')
            ->has('assembled.rules', 2)
            ->where('ruleDefaults.messageKey', 'compliance.rule.custom'));
    }

    public function test_the_rules_table_filters_searches_and_sorts(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $this->addRule($ruleSet, $this->rule(['id' => 'alpha', 'group' => 'g1']), 1);
        $this->addRule($ruleSet, $this->rule(['id' => 'beta', 'operator' => 'forbidden', 'limit' => null, 'source' => null, 'pendingSource' => true]), 2);
        $this->addRule($ruleSet, $this->rule(['id' => 'gamma']), 3)->update(['active' => false]);

        $keys = fn (string $query): array => array_column($this->get("/compliance/{$ruleSet->getKey()}?{$query}")->viewData('page')['props']['list']['rows'], 'rule_key');

        $this->assertSame(['alpha', 'beta', 'gamma'], $keys(''));
        $this->assertSame(['beta'], $keys('operator=forbidden'));
        $this->assertSame(['beta'], $keys('pending_source=1'));
        $this->assertSame(['alpha', 'beta'], $keys('active=1'));
        $this->assertSame(['alpha'], $keys('search=g1'));
        $this->assertSame(['gamma', 'beta', 'alpha'], $keys('sort=rule_key&dir=desc'));
        $this->assertSame(['alpha', 'gamma'], $keys('unit=%25w/w&operator=%3C%3D'));
    }

    public function test_it_adds_a_rule_with_a_source_and_reads_the_title_from_the_source(): void
    {
        $ruleSet = RuleSet::factory()->create();

        $rule = $this->rule();
        $rule['source']['title'] = 'A title typed by the browser';

        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $rule, 'message' => '  Keep it low  ', 'active' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $stored = $ruleSet->rules()->firstOrFail();
        $this->assertSame('Test standard', $stored->rule['source']['title']);
        $this->assertSame('0.5', $stored->rule['limit']);
        $this->assertSame('Keep it low', $stored->message);
        $this->assertSame(1, $stored->position);
        $this->assertSame('test-0001-limit', $stored->rule_key);
    }

    public function test_it_adds_a_pending_source_rule_to_a_draft(): void
    {
        $ruleSet = RuleSet::factory()->create();

        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule(['source' => null, 'pendingSource' => true])])->assertSessionHasNoErrors();

        $this->assertTrue($ruleSet->rules()->firstOrFail()->pending_source);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidRules(): array
    {
        return [
            'no source and not pending' => [['source' => null], 'rule.source'],
            'source and pending' => [['pendingSource' => true], 'rule.pendingSource'],
            'limit missing for a comparison' => [['limit' => null], 'rule.limit'],
            'limit on forbidden' => [['operator' => 'forbidden'], 'rule.limit'],
            'limit is not a decimal' => [['limit' => 'half'], 'rule.limit'],
            'unknown operator' => [['operator' => '~='], 'rule.operator'],
            'two targets' => [['target' => ['substanceCas' => 'A', 'inci' => 'B']], 'rule.target'],
            'unknown target key' => [['target' => ['colour' => 'red']], 'rule.target'],
            'no product type' => [['scope' => ['productTypes' => []]], 'rule.scope.productTypes'],
            'concentration not in percent' => [['unit' => 'g'], 'rule.unit'],
            'group on a non-upper limit' => [['group' => 'g', 'operator' => '>='], 'rule.group'],
            'group with a zero limit' => [['group' => 'g', 'limit' => '0'], 'rule.group'],
            'unknown key in the rule' => [['colour' => 'red'], 'rule'],
            'bad rule id' => [['id' => 'has space'], 'rule.id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidRules')]
    public function test_it_rejects_an_invalid_rule(array $override, string $errorKey): void
    {
        $ruleSet = RuleSet::factory()->create();

        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule($override)])->assertSessionHasErrors($errorKey);

        $this->assertSame(0, $ruleSet->rules()->count());
    }

    public function test_a_product_field_rule_may_use_another_unit_and_a_concentration_may_not(): void
    {
        $ruleSet = RuleSet::factory()->create();

        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule(['id' => 'ph', 'target' => ['field' => 'ph'], 'unit' => 'g/mL', 'limit' => '1.1'])])->assertSessionHasNoErrors();
        $this->assertSame(1, $ruleSet->rules()->count());
    }

    public function test_two_rules_cannot_share_an_id_in_one_rule_set_but_may_in_another(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $other = RuleSet::factory()->create();
        $this->addRule($ruleSet, $this->rule());

        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule()])->assertSessionHasErrors('rule.id');
        $this->post("/compliance/{$other->getKey()}/rules", ['rule' => $this->rule()])->assertSessionHasNoErrors();
    }

    public function test_it_updates_and_removes_a_rule(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $rule = $this->addRule($ruleSet, $this->rule());

        $this->put("/compliance/{$ruleSet->getKey()}/rules/{$rule->getKey()}", ['rule' => $this->rule(['limit' => '0.25']), 'message' => 'Tighter', 'active' => false])->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame('0.25', $rule->rule['limit']);
        $this->assertSame('Tighter', $rule->message);
        $this->assertFalse($rule->active);

        $this->delete("/compliance/{$ruleSet->getKey()}/rules/{$rule->getKey()}")->assertSessionHas('success');
        $this->assertSame(0, $ruleSet->rules()->count());
    }

    public function test_a_rule_of_another_rule_set_is_not_reachable_through_this_one(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $other = RuleSet::factory()->create();
        $rule = $this->addRule($other, $this->rule());

        $this->delete("/compliance/{$ruleSet->getKey()}/rules/{$rule->getKey()}")->assertNotFound();
    }

    public function test_rule_changes_are_audited(): void
    {
        $ruleSet = RuleSet::factory()->create();
        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule()]);

        $this->assertDatabaseHas('audit_log', ['table_name' => 'rules', 'action' => 'create']);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'rule_sets', 'record_id' => $ruleSet->getKey(), 'action' => 'create']);
    }
}
