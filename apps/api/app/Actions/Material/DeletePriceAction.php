<?php

namespace App\Actions\Material;

use App\Models\Price;

/** Removes one price quote; the audit trail keeps what it was. */
final class DeletePriceAction
{
    public function __invoke(Price $price): void
    {
        $price->delete();
    }
}
