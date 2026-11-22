<?php

namespace App\Actions\Pricing;

use App\Models\PriceListCustomer;

/** Takes a merchant off a price list. The next publish sends it as `unassign_customers`. */
final class UnassignPriceListCustomerAction
{
    public function __invoke(PriceListCustomer $assignment): void
    {
        $list = $assignment->priceList;
        $assignment->delete();
        $list->touch();
    }
}
