<?php

namespace App\Exceptions\Domain;

use App\Enums\FormulaStatus;

/** Thrown when a formula version cannot make the requested workflow move. */
class FormulaWorkflowException extends BusinessRuleViolationException
{
    /** @var list<string> */
    public array $missing = [];

    public static function transitionNotAllowed(FormulaStatus $from, FormulaStatus $to): self
    {
        return new self(__('exceptions.workflow_transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()]));
    }

    public static function reasonRequired(): self
    {
        return new self(__('exceptions.workflow_rejection_reason_required'));
    }

    public static function noCompletedTrial(): self
    {
        return new self(__('exceptions.workflow_no_completed_trial'));
    }

    /** @param  list<string>  $missing  The required measurements no completed trial holds (property keys). */
    public static function measurementsMissing(array $missing, string $labels): self
    {
        $exception = new self(__('exceptions.workflow_measurements_missing', ['measurements' => $labels]));
        $exception->missing = $missing;

        return $exception;
    }

    public static function hashMismatch(): self
    {
        return new self(__('exceptions.workflow_hash_mismatch'));
    }
}
