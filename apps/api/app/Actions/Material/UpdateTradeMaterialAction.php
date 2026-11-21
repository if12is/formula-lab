<?php

namespace App\Actions\Material;

use App\Enums\TradeMaterialStatus;
use App\Models\TradeMaterial;

/** Updates the descriptive fields of a trade material; the audit trail records the before and after. */
final class UpdateTradeMaterialAction
{
    /**
     * @param  array{trade_name: string, grade?: string|null, function?: string|null, substance_id?: string|null, is_mixture?: bool, status: TradeMaterialStatus|string}  $data
     */
    public function __invoke(TradeMaterial $material, array $data): TradeMaterial
    {
        $material->update($data);

        return $material;
    }
}
