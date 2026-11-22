<?php

namespace App\Actions\Fragrance;

use App\Models\IfraCertificate;
use App\Models\TradeMaterial;

/** Records one IFRA certificate of a fragrance oil. */
final class CreateIfraCertificateAction
{
    /** @param  array{certificate_number: string, ifra_amendment?: string|null, issuer?: string|null, issued_on: string, source_id: string, confidence: string}  $data */
    public function __invoke(TradeMaterial $material, array $data): IfraCertificate
    {
        return IfraCertificate::query()->create([...$data, 'tenant_id' => $material->tenant_id, 'trade_material_id' => $material->getKey()]);
    }
}
