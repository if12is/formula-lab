<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadRefType;
use App\Models\SanadProduct;
use App\Models\SanadRef;
use App\Models\TradeMaterial;
use Illuminate\Support\Str;

/**
 * Keeps an existing material link in step with the cached Sanad product: records the local row on the
 * sanad_refs entry, and drops the link when the product was deleted in Sanad (Sanad wins).
 * It never creates a link: matching products to materials is an explicit decision.
 */
final class ReconcileTradeMaterialLinkAction
{
    public function __invoke(string $tenantId, string $productUuid): ?TradeMaterial
    {
        $productUuid = Str::lower($productUuid);
        $material = TradeMaterial::query()
            ->where('tenant_id', $tenantId)
            ->where('sanad_product_uuid', $productUuid)
            ->first();
        if ($material === null) {
            return null;
        }

        $ref = SanadRef::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', SanadRefType::Product)
            ->where('sanad_uuid', $productUuid)
            ->first();

        $product = SanadProduct::query()->find($productUuid);
        $gone = $product === null || $product->deleted;

        if ($gone) {
            $material->forceFill(['sanad_product_uuid' => null])->save();
            $ref?->forceFill(['local_table' => null, 'local_id' => null])->save();

            return $material;
        }

        $ref?->forceFill(['local_table' => $material->getTable(), 'local_id' => $material->getKey()])->save();

        return $material;
    }
}
