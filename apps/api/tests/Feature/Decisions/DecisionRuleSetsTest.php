<?php

namespace Tests\Feature\Decisions;

use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The decision rule sets the owner approved on 2026-11-21 (packages/engine/data/decisions): published, and worded in both
 * languages. The engine decides; the app supplies the wording and the configured numbers.
 */
class DecisionRuleSetsTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function ruleSets(): array
    {
        $sets = [];

        foreach (glob(config('decisions.rule_sets_path').'/*.json') as $file) {
            $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $sets[$document['id']] = $document;
        }

        return $sets;
    }

    /** @return list<string> */
    private function wordingKeys(array $ruleSet): array
    {
        return collect($ruleSet['rules'])->flatMap(fn (array $rule): array => [$rule['messageKey'], $rule['remedyKey']])->unique()->values()->all();
    }

    public function test_the_three_rule_sets_are_published_approved_by_the_owner_on_the_decision_date(): void
    {
        $sets = $this->ruleSets();

        $this->assertEqualsCanonicalizing(['approve-formula-version', 'release-batch', 'publish-to-sanad'], array_keys($sets));

        foreach ($sets as $id => $set) {
            $this->assertSame('published', $set['status'], $id);
            $this->assertSame('owner', $set['approvedBy'], $id);
            $this->assertSame('2026-11-21', $set['approvedOn'], $id);
        }
    }

    public function test_every_message_and_remedy_key_is_worded_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/decisions.php"));

            foreach ($this->ruleSets() as $id => $set) {
                foreach ($this->wordingKeys($set) as $key) {
                    $this->assertStringStartsWith('decision.', $key, "{$id}: {$key}");
                    $lang = 'decisions.'.substr($key, strlen('decision.'));
                    $this->assertArrayHasKey(substr($lang, strlen('decisions.')), $texts, "lang/{$locale}/decisions.php lacks [{$lang}] used by {$id}");
                    $this->assertNotSame('', trim($texts[substr($lang, strlen('decisions.'))]));
                }
            }
        }
    }

    public function test_the_wording_only_uses_parameters_the_rule_provides(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/decisions.php"));

            foreach ($this->ruleSets() as $id => $set) {
                foreach ($set['rules'] as $rule) {
                    $key = substr($rule['messageKey'], strlen('decision.'));
                    preg_match_all('/:([A-Za-z]+)/', $texts[$key], $found);
                    $this->assertEqualsCanonicalizing(array_keys($rule['params'] ?? []), array_values(array_unique($found[1])), "{$locale} {$id} {$rule['id']}");
                }
            }
        }
    }

    public function test_the_reapproval_threshold_is_a_decimal_string_in_configuration_and_a_context_field_in_the_rule(): void
    {
        $threshold = config('decisions.reapproval_price_change_threshold_pct');

        $this->assertSame('5', $threshold);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', $threshold);

        $rule = collect($this->ruleSets()['publish-to-sanad']['rules'])->firstWhere('id', 'PS-04');
        $this->assertSame('repricing.thresholdPct', $rule['valueFrom']);
        $this->assertArrayNotHasKey('value', $rule);
        $this->assertStringNotContainsString($threshold.'"', json_encode(Arr::except($rule, ['basis'])));
    }

    public function test_the_threshold_wording_uses_the_parameter_so_a_changed_configuration_changes_the_text(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $text = __('decisions.ps.price_change_needs_reapproval', ['maxChangePct' => '7', 'thresholdPct' => '10', 'materialIds' => 'm1']);

            $this->assertStringContainsString('10', $text);
            $this->assertStringNotContainsString(':thresholdPct', $text);
        }
    }

    public function test_the_tax_group_is_shared_with_the_interface_and_the_decision_wording_stays_on_the_server(): void
    {
        $this->assertContains('tax', config('ui.shared_groups'));
        // Decision wording uses Laravel's :parameter placeholders and is worded on the server (the interface fills {name} ones).
        $this->assertNotContains('decisions', config('ui.shared_groups'));
    }
}
