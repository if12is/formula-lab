<?php

namespace App\Exceptions\Domain;

/** Thrown when an IFRA certificate cannot be removed or used as asked. */
class IfraCertificateException extends BusinessRuleViolationException
{
    public static function hasLimits(string $certificate, int $count): self
    {
        return new self(__('exceptions.ifra_certificate_has_limits', ['certificate' => $certificate, 'count' => $count]));
    }
}
