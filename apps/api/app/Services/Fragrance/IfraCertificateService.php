<?php

namespace App\Services\Fragrance;

use App\Actions\Fragrance\CreateIfraCertificateAction;
use App\Actions\Fragrance\DeleteIfraCertificateAction;
use App\Exceptions\Domain\IfraCertificateException;
use App\Models\IfraCertificate;
use App\Models\TradeMaterial;

/** The single write path of IFRA certificates on fragrance oils. */
final class IfraCertificateService
{
    public function __construct(
        private CreateIfraCertificateAction $createCertificate,
        private DeleteIfraCertificateAction $deleteCertificate,
    ) {}

    /** @param  array{certificate_number: string, ifra_amendment?: string|null, issuer?: string|null, issued_on: string, source_id: string, confidence: string}  $data */
    public function add(TradeMaterial $material, array $data): IfraCertificate
    {
        return ($this->createCertificate)($material, $data);
    }

    /** @throws IfraCertificateException */
    public function remove(IfraCertificate $certificate): void
    {
        ($this->deleteCertificate)($certificate);
    }
}
