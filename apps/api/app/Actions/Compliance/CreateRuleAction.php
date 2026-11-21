<?php

namespace App\Actions\Compliance;

use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Support\Compliance\RuleDocument;

/** Appends one rule (in the engine's rules DSL) to a draft rule set. */
final class CreateRuleAction
{
    /**
     * @param  array<string, mixed>  $rule  The DSL rule.
     *
     * @throws RuleSetException When the rule set is published or already has a rule with that id.
     */
    public function __invoke(RuleSet $ruleSet, array $rule, ?string $message, bool $active): ComplianceRule
    {
        if ($ruleSet->isPublished()) {
            throw RuleSetException::notDraft($ruleSet->code, $ruleSet->version);
        }

        $rule = RuleDocument::withSourceTitle($rule);
        $id = (string) ($rule['id'] ?? '');

        if ($ruleSet->rules()->where('rule_key', $id)->exists()) {
            throw RuleSetException::duplicateRuleId($id);
        }

        return ComplianceRule::query()->create([
            'rule_set_id' => $ruleSet->getKey(),
            'rule' => $rule,
            'message' => $message,
            'active' => $active,
            'position' => (int) $ruleSet->rules()->reorder()->max('position') + 1,
        ]);
    }
}
