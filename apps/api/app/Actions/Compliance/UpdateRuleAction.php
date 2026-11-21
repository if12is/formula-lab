<?php

namespace App\Actions\Compliance;

use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;
use App\Support\Compliance\RuleDocument;

/** Replaces one rule of a draft rule set. */
final class UpdateRuleAction
{
    /**
     * @param  array<string, mixed>  $rule  The DSL rule.
     *
     * @throws RuleSetException When the rule set is published or another rule already has that id.
     */
    public function __invoke(ComplianceRule $complianceRule, array $rule, ?string $message, bool $active): ComplianceRule
    {
        $ruleSet = $complianceRule->ruleSet;

        if ($ruleSet->isPublished()) {
            throw RuleSetException::notDraft($ruleSet->code, $ruleSet->version);
        }

        $rule = RuleDocument::withSourceTitle($rule);
        $id = (string) ($rule['id'] ?? '');

        if ($ruleSet->rules()->where('rule_key', $id)->whereKeyNot($complianceRule->getKey())->exists()) {
            throw RuleSetException::duplicateRuleId($id);
        }

        $complianceRule->update(['rule' => $rule, 'message' => $message, 'active' => $active]);

        return $complianceRule;
    }
}
