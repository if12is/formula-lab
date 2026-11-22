<?php

namespace App\Actions\Packaging;

use App\Models\TradeMaterial;

/** Inserts one packaging component (a trade-material row of a packaging item kind). */
final class CreatePackagingItemAction
{
    /**
     * @param  array<string, mixed>  $data  The fields of StorePackagingItemRequest::packagingData().
     */
    public function __invoke(array $data): TradeMaterial
    {
        return TradeMaterial::query()->create($data);
    }
}
