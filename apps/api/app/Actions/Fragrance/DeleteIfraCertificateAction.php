<?php

namespace App\Actions\Fragrance;

use App\Exceptions\Domain\IfraCertificateException;
use App\Models\IfraCertificate;

/** Removes an IFRA certificate that no limit is read from; the audit trail keeps what it was. */
final class DeleteIfraCertificateAction
{
    /** @throws IfraCertificateException When limits still point at the certificate. */
    public function __invoke(IfraCertificate $certificate): void
    {
        $count = $certificate->limits()->count();

        if ($count > 0) {
            throw IfraCertificateException::hasLimits($certificate->certificate_number, $count);
        }

        $certificate->delete();
    }
}
