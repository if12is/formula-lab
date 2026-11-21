<?php

namespace App\Exceptions\Domain;

/** Thrown when code tries to change or delete a record that must stay immutable. */
class ImmutableRecordException extends BusinessRuleViolationException
{
    public static function approvedFormulaVersion(string $versionId): self
    {
        return new self(__('exceptions.approved_formula_version_immutable', ['version' => $versionId]));
    }

    public static function publishedRuleSet(string $ruleSetId): self
    {
        return new self(__('exceptions.published_rule_set_immutable', ['rule_set' => $ruleSetId]));
    }

    public static function appendOnly(string $table): self
    {
        return new self(__('exceptions.append_only', ['table' => $table]));
    }
}
