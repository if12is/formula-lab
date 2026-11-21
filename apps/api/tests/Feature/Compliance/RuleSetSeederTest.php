<?php

namespace Tests\Feature\Compliance;

use App\Enums\RuleOperator;
use App\Enums\RuleSetStatus;
use App\Enums\RuleTargetType;
use App\Models\RuleSet;
use Database\Seeders\ComplianceRuleSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleSetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_methanol_rule_as_a_draft_that_is_pending_a_source(): void
    {
        $this->seed(ComplianceRuleSetSeeder::class);

        $ruleSet = RuleSet::query()->where('code', 'methanol-fragrance')->firstOrFail();
        $rule = $ruleSet->rules()->firstOrFail();

        $this->assertSame(RuleSetStatus::Draft, $ruleSet->status);
        $this->assertNull($ruleSet->hash);
        $this->assertSame('forbidden', $rule->rule['operator']);
        $this->assertSame('67-56-1', $rule->rule['target']['substanceCas']);
        $this->assertSame(['fine_fragrance', 'air_freshener'], $rule->rule['scope']['productTypes']);
        $this->assertNull($rule->rule['source']);
        $this->assertTrue($rule->rule['pendingSource']);
        $this->assertTrue($rule->pending_source);
    }

    public function test_it_is_safe_to_run_again(): void
    {
        $this->seed(ComplianceRuleSetSeeder::class);
        $this->seed(ComplianceRuleSetSeeder::class);

        $this->assertSame(1, RuleSet::query()->where('code', 'methanol-fragrance')->count());
        $this->assertSame(1, RuleSet::query()->where('code', 'methanol-fragrance')->firstOrFail()->rules()->count());
    }

    public function test_the_seeded_draft_can_never_be_published_while_the_source_is_pending(): void
    {
        $this->seed(ComplianceRuleSetSeeder::class);
        $ruleSet = RuleSet::query()->where('code', 'methanol-fragrance')->firstOrFail();

        $this->post("/compliance/{$ruleSet->getKey()}/publish")->assertSessionHas('error');

        $this->assertSame(RuleSetStatus::Draft, $ruleSet->fresh()->status);
    }

    public function test_the_shipped_file_is_listed_with_its_wording_on_the_list_page(): void
    {
        $this->seed(ComplianceRuleSetSeeder::class);

        $this->get('/compliance?search=methanol')->assertInertia(fn ($page) => $page
            ->where('list.meta.total', 1)
            ->where('list.rows.0.status', 'draft')
            ->where('list.rows.0.rules_count', 1)
            ->where('list.rows.0.product_types', ['air_freshener', 'fine_fragrance']));
    }

    /** The PHP enums mirror the engine's DSL vocabulary; this fails if the engine's lists change. */
    public function test_the_operators_and_targets_match_the_engines_rules_dsl(): void
    {
        $dsl = (string) file_get_contents(base_path('../../packages/engine/src/compliance/dsl.ts'));

        preg_match('/OPERATORS = \\[([^\\]]+)\\]/', $dsl, $operators);
        preg_match('/TARGET_KEYS = \\[([^\\]]+)\\]/', $dsl, $targets);

        $engineOperators = array_map(fn (string $item): string => trim($item, " '"), explode(',', $operators[1]));
        $engineTargets = array_map(fn (string $item): string => trim($item, " '"), explode(',', $targets[1]));

        $this->assertEqualsCanonicalizing($engineOperators, RuleOperator::values());
        $this->assertEqualsCanonicalizing($engineTargets, RuleTargetType::values());
    }
}
