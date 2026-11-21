<?php

namespace App\Actions\Compliance;

use App\Exceptions\Domain\RuleSetException;
use App\Models\RuleSet;

/** Changes the name, notes, version and source of a draft rule set. */
final class UpdateRuleSetAction
{
    /**
     * @param  array{code?: string, version?: string, name?: string, scope_notes?: string|null, source_id?: string|null}  $data
     *
     * @throws RuleSetException When the rule set is published or the new version already exists.
     */
    public function __invoke(RuleSet $ruleSet, array $data): RuleSet
    {
        if ($ruleSet->isPublished()) {
            throw RuleSetException::notDraft($ruleSet->code, $ruleSet->version);
        }

        $code = $data['code'] ?? $ruleSet->code;
        $version = $data['version'] ?? $ruleSet->version;

        if (RuleSet::query()->where('code', $code)->where('version', $version)->whereKeyNot($ruleSet->getKey())->exists()) {
            throw RuleSetException::duplicateVersion($code, $version);
        }

        $ruleSet->update($data);

        return $ruleSet;
    }
}
