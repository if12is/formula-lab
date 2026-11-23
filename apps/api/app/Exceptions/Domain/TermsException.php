<?php

namespace App\Exceptions\Domain;

/** Thrown when an acceptance of the terms cannot be recorded as it was sent (S-16). */
class TermsException extends BusinessRuleViolationException
{
    public static function versionChanged(string $submitted, string $current): self
    {
        return new self(__('exceptions.terms_version_changed', ['submitted' => $submitted, 'current' => $current]));
    }
}
