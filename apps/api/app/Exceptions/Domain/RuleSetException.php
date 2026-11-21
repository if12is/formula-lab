<?php

namespace App\Exceptions\Domain;

/** Thrown when a rule set or one of its rules would break the rule-set rules (drafts only are edited, publishing must validate). */
class RuleSetException extends BusinessRuleViolationException
{
    /** @param  list<array{property: string, reason: string}>  $issues  What the engine's validator reported. */
    public function __construct(string $message, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    public static function notDraft(string $code, string $version): self
    {
        return new self(__('exceptions.rule_set_not_draft', ['code' => $code, 'version' => $version]));
    }

    public static function duplicateVersion(string $code, string $version): self
    {
        return new self(__('exceptions.rule_set_duplicate_version', ['code' => $code, 'version' => $version]));
    }

    public static function duplicateRuleId(string $ruleId): self
    {
        return new self(__('exceptions.rule_duplicate_id', ['rule' => $ruleId]));
    }

    public static function noRules(): self
    {
        return new self(__('exceptions.rule_set_no_rules'));
    }

    public static function changedSinceReview(): self
    {
        return new self(__('exceptions.rule_set_changed_since_review'));
    }

    /** @param  list<array{property: string, reason: string}>  $issues */
    public static function invalid(array $issues): self
    {
        $lines = array_map(
            fn (array $issue): string => __("engine.{$issue['reason']}").' ('.$issue['property'].')',
            array_slice($issues, 0, 5),
        );

        return new self(__('exceptions.rule_set_invalid', ['count' => count($issues), 'issues' => implode('; ', $lines)]), $issues);
    }
}
