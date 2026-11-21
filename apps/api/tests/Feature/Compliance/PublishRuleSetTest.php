<?php

namespace Tests\Feature\Compliance;

use App\Actions\Compliance\PublishRuleSetAction;
use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\EngineException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Support\Compliance\RuleSetAssembler;
use App\Support\Engine\EngineRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsRules;
use Tests\TestCase;

/** Publishing validates with the engine (a Node process) and freezes the rule set with its hash. */
class PublishRuleSetTest extends TestCase
{
    use BuildsRules, RefreshDatabase;

    private function draft(): RuleSet
    {
        $ruleSet = RuleSet::factory()->create(['code' => 'pub-set', 'version' => '1.0.0']);
        $this->addRule($ruleSet, $this->rule());

        return $ruleSet;
    }

    public function test_it_publishes_a_valid_draft_with_the_engines_hash(): void
    {
        $ruleSet = $this->draft();
        $expected = app(EngineRunner::class)->run('validate-rule-set', ['ruleSet' => app(RuleSetAssembler::class)->assemble($ruleSet, RuleSetStatus::Published)]);

        $this->post("/compliance/{$ruleSet->getKey()}/publish")->assertSessionHas('success')->assertSessionMissing('error');

        $ruleSet->refresh();
        $this->assertSame(RuleSetStatus::Published, $ruleSet->status);
        $this->assertSame($expected['hash'], $ruleSet->hash);
        $this->assertNotNull($ruleSet->published_at);
        // The assembled JSON of the published set now hashes to the stored hash, which the editor checks.
        $again = app(EngineRunner::class)->run('validate-rule-set', ['ruleSet' => app(RuleSetAssembler::class)->assemble($ruleSet)]);
        $this->assertSame($ruleSet->hash, $again['hash']);
    }

    public function test_it_publishes_when_the_confirmed_hash_is_still_the_hash(): void
    {
        $ruleSet = $this->draft();
        $hash = app(EngineRunner::class)->run('validate-rule-set', ['ruleSet' => app(RuleSetAssembler::class)->assemble($ruleSet, RuleSetStatus::Published)])['hash'];

        $this->post("/compliance/{$ruleSet->getKey()}/publish", ['expected_hash' => $hash])->assertSessionHas('success');

        $this->assertTrue($ruleSet->fresh()->isPublished());
    }

    public function test_it_refuses_when_the_rules_changed_since_the_hash_was_reviewed(): void
    {
        $ruleSet = $this->draft();

        $this->post("/compliance/{$ruleSet->getKey()}/publish", ['expected_hash' => str_repeat('a', 64)])->assertSessionHas('error');

        $this->assertSame(RuleSetStatus::Draft, $ruleSet->fresh()->status);
    }

    public function test_the_engine_refuses_a_rule_that_still_waits_for_its_source(): void
    {
        $ruleSet = $this->draft();
        $this->addRule($ruleSet, $this->rule(['id' => 'pending', 'source' => null, 'pendingSource' => true]), 2);

        $response = $this->post("/compliance/{$ruleSet->getKey()}/publish");

        $response->assertSessionHas('error');
        $this->assertStringContainsString('pending', (string) session('error'));
        $this->assertSame(RuleSetStatus::Draft, $ruleSet->fresh()->status);
        $this->assertNull($ruleSet->fresh()->hash);
    }

    public function test_the_exception_lists_what_the_engine_found(): void
    {
        $ruleSet = $this->draft();
        $this->addRule($ruleSet, $this->rule(['id' => 'pending', 'source' => null, 'pendingSource' => true]), 2);

        try {
            app(PublishRuleSetAction::class)($ruleSet);
            $this->fail('Publishing should have been refused.');
        } catch (RuleSetException $exception) {
            $this->assertSame('dsl.pending_source_only_in_draft', $exception->issues[0]['reason']);
            $this->assertSame('$.rules[1].pendingSource', $exception->issues[0]['property']);
        }
    }

    public function test_an_inactive_rule_is_left_out_of_what_is_published(): void
    {
        $ruleSet = $this->draft();
        $this->addRule($ruleSet, $this->rule(['id' => 'pending', 'source' => null, 'pendingSource' => true]), 2)->update(['active' => false]);

        $this->post("/compliance/{$ruleSet->getKey()}/publish")->assertSessionHas('success');

        $this->assertCount(1, app(RuleSetAssembler::class)->assemble($ruleSet->fresh())['rules']);
    }

    public function test_it_refuses_to_publish_a_rule_set_with_no_active_rules(): void
    {
        $ruleSet = RuleSet::factory()->create();

        $this->post("/compliance/{$ruleSet->getKey()}/publish")->assertSessionHas('error');

        $this->assertSame(RuleSetStatus::Draft, $ruleSet->fresh()->status);
    }

    public function test_it_cannot_publish_twice(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);

        $this->assertThrowsException(RuleSetException::class, fn () => app(PublishRuleSetAction::class)($ruleSet->fresh()));
    }

    public function test_an_engine_failure_is_shown_as_an_error_and_nothing_is_published(): void
    {
        $ruleSet = $this->draft();
        Process::fake(['*' => Process::result(errorOutput: 'node is missing', exitCode: 127)]);

        $this->post("/compliance/{$ruleSet->getKey()}/publish")->assertSessionHas('error');

        $this->assertSame(RuleSetStatus::Draft, $ruleSet->fresh()->status);
        $this->assertThrowsException(EngineException::class, fn () => app(PublishRuleSetAction::class)($ruleSet), 'node is missing');
    }

    public function test_a_published_rule_set_and_its_rules_are_immutable_in_the_application(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);
        $ruleSet->refresh();
        $rule = $ruleSet->rules()->firstOrFail();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $ruleSet->update(['name' => 'Changed']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $ruleSet->delete());
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $rule->update(['active' => false]));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $rule->delete());
        $this->assertThrowsException(ImmutableRecordException::class, fn () => ComplianceRule::factory()->create(['rule_set_id' => $ruleSet->getKey()]));
    }

    public function test_a_published_rule_set_is_immutable_through_the_interface(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);
        $rule = $ruleSet->rules()->firstOrFail();

        $this->put("/compliance/{$ruleSet->getKey()}", ['code' => 'pub-set', 'version' => '1.0.0', 'name' => 'Changed'])->assertSessionHasErrors('version');
        $this->post("/compliance/{$ruleSet->getKey()}/rules", ['rule' => $this->rule(['id' => 'late'])])->assertSessionHasErrors('rule.id');
        $this->put("/compliance/{$ruleSet->getKey()}/rules/{$rule->getKey()}", ['rule' => $this->rule(['limit' => '9'])])->assertSessionHasErrors('rule.id');
        $this->delete("/compliance/{$ruleSet->getKey()}/rules/{$rule->getKey()}")->assertSessionHas('error');
        $this->delete("/compliance/{$ruleSet->getKey()}")->assertSessionHas('error');

        $this->assertSame('0.5', $rule->fresh()->rule['limit']);
        $this->assertSame(1, $ruleSet->rules()->count());
        $this->assertNotNull(RuleSet::query()->find($ruleSet->getKey()));
    }

    public function test_the_database_itself_refuses_to_change_a_published_rule_set_or_its_rules(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);
        $id = $ruleSet->getKey();

        $statements = [
            ['update rule_sets set name = ? where id = ?', ['Changed', $id]],
            ['delete from rule_sets where id = ?', [$id]],
            ['update rules set message = ? where rule_set_id = ?', ['Changed', $id]],
            ['delete from rules where rule_set_id = ?', [$id]],
            ["insert into rules (id, rule_set_id, rule, active, position, created_at, updated_at) values (gen_random_uuid(), ?, '{\"id\":\"x\"}', true, 9, now(), now())", [$id]],
        ];

        foreach ($statements as [$sql, $bindings]) {
            DB::beginTransaction();

            try {
                DB::statement($sql, $bindings);
                $this->fail("The database accepted: {$sql}");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_a_published_rule_set_cannot_go_back_to_draft_even_with_sql(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);

        DB::beginTransaction();

        try {
            DB::statement("update rule_sets set status = 'draft', hash = null, published_at = null where id = ?", [$ruleSet->getKey()]);
            $this->fail('A published rule set went back to draft.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_new_version_is_a_draft_copy_of_a_published_rule_set(): void
    {
        $ruleSet = $this->draft();
        app(PublishRuleSetAction::class)($ruleSet);

        $this->post("/compliance/{$ruleSet->getKey()}/versions", ['version' => '1.1.0'])->assertSessionHasNoErrors();

        $copy = RuleSet::query()->where('code', 'pub-set')->where('version', '1.1.0')->firstOrFail();
        $this->assertSame(RuleSetStatus::Draft, $copy->status);
        $this->assertNull($copy->hash);
        $this->assertSame('1.1.0', $copy->rules()->firstOrFail()->rule['version']);
        $this->assertSame($ruleSet->rules()->firstOrFail()->rule['limit'], $copy->rules()->firstOrFail()->rule['limit']);
        $this->assertTrue($ruleSet->fresh()->isPublished());

        $this->post("/compliance/{$ruleSet->getKey()}/versions", ['version' => '1.1.0'])->assertSessionHasErrors('version');
    }

    public function test_the_assembler_writes_the_status_it_is_asked_for_and_skips_inactive_rules(): void
    {
        $ruleSet = $this->draft();
        $this->addRule($ruleSet, $this->rule(['id' => 'off']), 2)->update(['active' => false]);
        $assembler = app(RuleSetAssembler::class);

        $this->assertSame('draft', $assembler->assemble($ruleSet)['status']);
        $this->assertSame('published', $assembler->assemble($ruleSet, RuleSetStatus::Published)['status']);
        $this->assertSame(['test-0001-limit'], array_column($assembler->assemble($ruleSet)['rules'], 'id'));
        $this->assertSame('pub-set', $assembler->assemble($ruleSet)['id']);
    }
}
