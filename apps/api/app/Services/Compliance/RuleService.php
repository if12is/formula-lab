<?php

namespace App\Services\Compliance;

use App\Actions\Compliance\CreateRuleAction;
use App\Actions\Compliance\DeleteRuleAction;
use App\Actions\Compliance\UpdateRuleAction;
use App\Models\ComplianceRule;
use App\Models\RuleSet;

/** The single write path of the rules inside a draft rule set. */
final class RuleService
{
    public function __construct(
        private CreateRuleAction $createRule,
        private UpdateRuleAction $updateRule,
        private DeleteRuleAction $deleteRule,
    ) {}

    /** @param  array<string, mixed>  $rule  The DSL rule. */
    public function add(RuleSet $ruleSet, array $rule, ?string $message, bool $active): ComplianceRule
    {
        return ($this->createRule)($ruleSet, $rule, $message, $active);
    }

    /** @param  array<string, mixed>  $rule  The DSL rule. */
    public function update(ComplianceRule $complianceRule, array $rule, ?string $message, bool $active): ComplianceRule
    {
        return ($this->updateRule)($complianceRule, $rule, $message, $active);
    }

    public function remove(ComplianceRule $complianceRule): void
    {
        ($this->deleteRule)($complianceRule);
    }
}
