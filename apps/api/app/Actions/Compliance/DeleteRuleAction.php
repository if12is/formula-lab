<?php

namespace App\Actions\Compliance;

use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;

/** Removes one rule from a draft rule set. */
final class DeleteRuleAction
{
    /** @throws RuleSetException When the rule set is published. */
    public function __invoke(ComplianceRule $complianceRule): void
    {
        $ruleSet = $complianceRule->ruleSet;

        if ($ruleSet->isPublished()) {
            throw RuleSetException::notDraft($ruleSet->code, $ruleSet->version);
        }

        $complianceRule->delete();
    }
}
