<?php

namespace App\Actions\Packaging;

use App\Models\PackagingBom;

/** Creates the packaging BOM of a SKU (no lines yet). */
final class CreatePackagingBomAction
{
    /** @param  array{name: string, sku_code?: string|null, sanad_product_uuid?: string|null, units_per_carton?: int|null, cartons_per_pallet?: int|null, note?: string|null}  $data */
    public function __invoke(array $data): PackagingBom
    {
        return PackagingBom::query()->create($data);
    }
}
