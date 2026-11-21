<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadItemKind;
use App\Enums\Sanad\SanadRefType;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\SanadProduct;
use App\Models\SanadRef;
use App\Models\TradeMaterial;
use Illuminate\Support\Facades\DB;

/**
 * Links a tenant's trade material to a cached Sanad raw-material or packaging product. UI-free: the caller
 * decides which product matches which material (names are never matched automatically).
 */
final class LinkTradeMaterialToSanadProductAction
{
    /**
     * @throws SanadLinkException When the tenant, kind or an existing link makes the link invalid.
     */
    public function __invoke(TradeMaterial $material, SanadProduct $product): TradeMaterial
    {
        if ($material->tenant_id === null || $material->tenant_id !== $product->tenant_id) {
            throw SanadLinkException::tenantMismatch();
        }
        if ($product->deleted) {
            throw SanadLinkException::productDeleted($product->uuid);
        }
        if (! in_array($product->item_kind, [SanadItemKind::RawMaterial, SanadItemKind::Packaging], true)) {
            throw SanadLinkException::productNotLinkable($product->item_kind->value);
        }
        if ($material->sanad_product_uuid === $product->uuid) {
            return $material;
        }
        if ($material->sanad_product_uuid !== null) {
            throw SanadLinkException::materialAlreadyLinked($material->getKey());
        }
        if (TradeMaterial::query()->where('tenant_id', $product->tenant_id)->where('sanad_product_uuid', $product->uuid)->exists()) {
            throw SanadLinkException::productAlreadyLinked($product->uuid);
        }

        return DB::transaction(function () use ($material, $product): TradeMaterial {
            $material->forceFill(['sanad_product_uuid' => $product->uuid])->save();

            SanadRef::query()
                ->where('tenant_id', $product->tenant_id)
                ->where('entity_type', SanadRefType::Product)
                ->where('sanad_uuid', $product->uuid)
                ->update(['local_table' => $material->getTable(), 'local_id' => $material->getKey()]);

            return $material;
        });
    }
}
