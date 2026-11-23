<?php

namespace App\Exceptions\Domain;

/** Thrown when a production order, a batch or a deviation would break the production rules (phase Q). */
class ProductionException extends BusinessRuleViolationException
{
    public static function versionNotApproved(string $formula, int $version): self
    {
        return new self(__('exceptions.production_version_not_approved', ['formula' => $formula, 'version' => $version]));
    }

    public static function versionHashMismatch(string $formula, int $version): self
    {
        return new self(__('exceptions.production_version_hash_mismatch', ['formula' => $formula, 'version' => $version]));
    }

    /** @param  list<string>  $reasons */
    public static function notWeighable(array $reasons): self
    {
        return new self(__('exceptions.production_not_weighable', ['reasons' => implode('; ', $reasons)]));
    }

    public static function orderNotDraft(string $code): self
    {
        return new self(__('exceptions.production_order_not_draft', ['order' => $code]));
    }

    public static function orderNotApproved(string $code): self
    {
        return new self(__('exceptions.production_order_not_approved', ['order' => $code]));
    }

    public static function approverRequired(): self
    {
        return new self(__('exceptions.production_approver_required'));
    }

    /** @param  list<string>  $reasons */
    public static function sendBlocked(string $code, array $reasons): self
    {
        return new self(__('exceptions.production_send_blocked', ['order' => $code, 'reasons' => implode('; ', $reasons)]));
    }

    public static function batchClosed(string $number): self
    {
        return new self(__('exceptions.production_batch_closed', ['batch' => $number]));
    }

    public static function batchAlreadyStarted(string $order): self
    {
        return new self(__('exceptions.production_batch_already_started', ['order' => $order]));
    }

    public static function lineNotInBatch(): self
    {
        return new self(__('exceptions.production_line_not_in_batch'));
    }

    public static function checkNotInBatch(int $step, int $check): self
    {
        return new self(__('exceptions.production_check_not_in_batch', ['step' => $step, 'check' => $check]));
    }

    /** @param  list<string>  $reasons */
    public static function cannotComplete(string $number, array $reasons): self
    {
        return new self(__('exceptions.production_cannot_complete', ['batch' => $number, 'reasons' => implode('; ', $reasons)]));
    }

    public static function deviationDecided(): self
    {
        return new self(__('exceptions.production_deviation_decided'));
    }

    public static function deviationNeedsNumbers(): self
    {
        return new self(__('exceptions.production_deviation_needs_numbers'));
    }

    /** @param  list<string>  $gaps */
    public static function calculationIncomplete(array $gaps): self
    {
        return new self(__('exceptions.production_calculation_incomplete', ['gaps' => implode('; ', $gaps)]));
    }

    public static function alkaliNotApplicable(): self
    {
        return new self(__('exceptions.production_alkali_not_applicable'));
    }
}
