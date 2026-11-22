<?php

namespace App\Actions\Pricing;

use App\Models\PriceList;
use Illuminate\Database\QueryException;

/** Renames a price list or switches it on or off (an inactive list is not used for sales in Sanad). */
final class UpdatePriceListAction
{
    /**
     * @param  array{name?: string, is_active?: bool}  $data
     *
     * @throws QueryException When the name is already used inside the tenant.
     */
    public function __invoke(PriceList $list, array $data): PriceList
    {
        $list->fill(array_intersect_key($data, array_flip(['name', 'is_active'])))->save();

        return $list;
    }
}
