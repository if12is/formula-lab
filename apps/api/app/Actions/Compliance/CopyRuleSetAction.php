<?php

namespace App\Actions\Compliance;

use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;

/** A new draft version of a rule set, with a copy of its rules: how a published (immutable) rule set is changed. */
final class CopyRuleSetAction
{
    /** @throws RuleSetException When that version of the code already exists. */
    public function __invoke(RuleSet $source, string $version, ?int $userId = null): RuleSet
    {
        if (RuleSet::query()->where('code', $source->code)->where('version', $version)->exists()) {
            throw RuleSetException::duplicateVersion($source->code, $version);
        }

        return DB::transaction(function () use ($source, $version, $userId): RuleSet {
            $copy = RuleSet::query()->create([
                'tenant_id' => $source->tenant_id,
                'code' => $source->code,
                'version' => $version,
                'name' => $source->name,
                'scope_notes' => $source->scope_notes,
                'status' => RuleSetStatus::Draft,
                'source_id' => $source->source_id,
                'created_by' => $userId,
            ]);

            $source->rules()->get()->each(fn (ComplianceRule $rule) => ComplianceRule::query()->create([
                'rule_set_id' => $copy->getKey(),
                'rule' => [...$rule->rule, 'version' => $version],
                'message' => $rule->message,
                'active' => $rule->active,
                'position' => $rule->position,
            ]));

            return $copy;
        });
    }
}
