<?php

namespace Tests\Concerns;

use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Models\Source;

/** Rules in the engine's rules DSL, as the rule form sends them. */
trait BuildsRules
{
    /**
     * A valid rule: at most 0.5 % w/w of a fictional substance, with a source.
     *
     * @param  array<string, mixed>  $override  Merged over the defaults; `source` and `scope` replace, they are not merged key by key.
     * @return array<string, mixed>
     */
    protected function rule(array $override = []): array
    {
        $source = Source::factory()->create(['title' => 'Test standard']);

        return [
            'id' => 'test-0001-limit',
            'version' => '1.0.0',
            'scope' => ['productTypes' => ['shampoo']],
            'target' => ['substanceCas' => 'TEST-0001'],
            'operator' => '<=',
            'limit' => '0.5',
            'unit' => '%w/w',
            'source' => ['sourceId' => $source->getKey(), 'title' => 'Test standard', 'ref' => 'Clause 1'],
            'messageKey' => 'compliance.rule.custom',
            ...$override,
        ];
    }

    /** @param  array<string, mixed>  $rule */
    protected function addRule(RuleSet $ruleSet, array $rule, int $position = 1): ComplianceRule
    {
        return ComplianceRule::factory()->create(['rule_set_id' => $ruleSet->getKey(), 'rule' => $rule, 'position' => $position]);
    }
}
