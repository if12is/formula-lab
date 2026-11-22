<?php

namespace App\Actions\Pricing;

use App\Models\PriceListItem;

/** Takes a product off a price list. The next publish tells Sanad to drop it (price null). */
final class RemovePriceListItemAction
{
    public function __invoke(PriceListItem $item): void
    {
        $list = $item->priceList;
        $item->delete();
        $list->touch();
    }
}
