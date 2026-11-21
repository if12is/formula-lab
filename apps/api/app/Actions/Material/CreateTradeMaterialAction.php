<?php

namespace App\Actions\Material;

use App\Enums\TradeMaterialStatus;
use App\Models\TradeMaterial;

/** Inserts one trade material (a commercial grade). */
final class CreateTradeMaterialAction
{
    /**
     * @param  array{trade_name: string, grade?: string|null, function?: string|null, substance_id?: string|null, is_mixture?: bool, status: TradeMaterialStatus|string}  $data
     */
    public function __invoke(array $data): TradeMaterial
    {
        return TradeMaterial::query()->create($data);
    }
}
