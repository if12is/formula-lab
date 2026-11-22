<?php

namespace App\Exceptions\Domain;

/** Thrown when a blocked decision cannot be overridden: nobody signed in, a role that may not, a wrong password, or nothing to override. */
class DecisionOverrideException extends BusinessRuleViolationException
{
    /** The form field the message belongs to. */
    public string $field = 'override';

    public static function notSignedIn(): self
    {
        return new self(__('exceptions.override_not_signed_in'));
    }

    public static function roleNotAllowed(): self
    {
        return new self(__('exceptions.override_role_not_allowed'));
    }

    public static function wrongPassword(): self
    {
        return self::onPassword(new self(__('exceptions.override_wrong_password')));
    }

    public static function tooManyAttempts(int $seconds): self
    {
        return self::onPassword(new self(__('exceptions.override_too_many_attempts', ['seconds' => $seconds])));
    }

    public static function notBlocked(): self
    {
        return new self(__('exceptions.override_not_blocked'));
    }

    public static function undecidable(): self
    {
        return new self(__('exceptions.override_undecidable'));
    }

    private static function onPassword(self $exception): self
    {
        $exception->field = 'password';

        return $exception;
    }
}
