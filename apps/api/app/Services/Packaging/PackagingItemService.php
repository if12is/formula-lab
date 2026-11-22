<?php

namespace App\Services\Packaging;

use App\Actions\Packaging\CreatePackagingItemAction;
use App\Actions\Packaging\UpdatePackagingItemAction;
use App\Models\TradeMaterial;

/** The single write path of packaging components. */
final class PackagingItemService
{
    public function __construct(
        private CreatePackagingItemAction $createItem,
        private UpdatePackagingItemAction $updateItem,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): TradeMaterial
    {
        return ($this->createItem)($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(TradeMaterial $item, array $data): TradeMaterial
    {
        return ($this->updateItem)($item, $data);
    }
}
