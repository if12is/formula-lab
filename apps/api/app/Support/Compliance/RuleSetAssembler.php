<?php

namespace App\Support\Compliance;

use App\Enums\RuleSetStatus;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use Illuminate\Support\Collection;

/**
 * Builds a rule set exactly as the engine's rules DSL reads it ({ id, version, status, rules }): the one place the stored
 * rows become that JSON, for the browser (validation, hash preview, evaluation) and for the server (publishing).
 * Inactive rules are left out, in rule order. The rule objects are the stored JSON, untouched.
 */
final class RuleSetAssembler
{
    /**
     * @param  RuleSetStatus|null  $as  The status to write into the JSON; null keeps the rule set's own (a published hash is of `published`).
     * @return array{id: string, version: string, status: string, rules: list<array<string, mixed>>}
     */
    public function assemble(RuleSet $ruleSet, ?RuleSetStatus $as = null): array
    {
        return [
            'id' => $ruleSet->code,
            'version' => $ruleSet->version,
            'status' => ($as ?? $ruleSet->status)->value,
            'rules' => $this->activeRules($ruleSet)->map(fn (ComplianceRule $rule): array => $rule->rule)->values()->all(),
        ];
    }

    /**
     * The active rules in rule order; uses the `rules` relation when the caller already loaded it.
     *
     * @return Collection<int, ComplianceRule>
     */
    private function activeRules(RuleSet $ruleSet): Collection
    {
        return $ruleSet->relationLoaded('rules')
            ? $ruleSet->rules->where('active', true)
            : $ruleSet->rules()->where('active', true)->get();
    }
}
