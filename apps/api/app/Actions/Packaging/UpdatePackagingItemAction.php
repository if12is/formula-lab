<?php

namespace App\Actions\Packaging;

use App\Models\TradeMaterial;

/** Updates a packaging component; the audit trail records the before and after. */
final class UpdatePackagingItemAction
{
    /**
     * @param  array<string, mixed>  $data  The fields of StorePackagingItemRequest::packagingData().
     */
    public function __invoke(TradeMaterial $item, array $data): TradeMaterial
    {
        $item->update($data);

        return $item;
    }
}
