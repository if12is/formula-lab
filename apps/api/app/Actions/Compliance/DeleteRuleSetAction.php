<?php

namespace App\Actions\Compliance;

use App\Exceptions\Domain\RuleSetException;
use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;

/** Deletes a draft rule set with its rules. A published one is never deleted. */
final class DeleteRuleSetAction
{
    /** @throws RuleSetException When the rule set is published. */
    public function __invoke(RuleSet $ruleSet): void
    {
        if ($ruleSet->isPublished()) {
            throw RuleSetException::notDraft($ruleSet->code, $ruleSet->version);
        }

        DB::transaction(function () use ($ruleSet): void {
            // Rule by rule, so each deletion is audited like any other.
            $ruleSet->rules()->get()->each->delete();
            $ruleSet->delete();
        });
    }
}
