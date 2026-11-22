<?php

namespace App\Actions\Pricing;

use App\Exceptions\Domain\PriceListException;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\SanadCustomer;

/**
 * Puts a merchant (a cached Sanad customer) on a price list. A Sanad customer follows one list, so a customer already on
 * another list is refused, and an inactive or archived customer cannot be assigned. Assigning again is a no-op.
 */
final class AssignPriceListCustomerAction
{
    /** @throws PriceListException */
    public function __invoke(PriceList $list, SanadCustomer $customer): PriceListCustomer
    {
        if ($customer->tenant_id !== $list->tenant_id) {
            throw PriceListException::crossTenant();
        }

        $existing = PriceListCustomer::query()->with('priceList')->where('customer_uuid', $customer->uuid)->first();
        if ($existing !== null) {
            if ($existing->price_list_id === $list->getKey()) {
                return $existing;
            }

            throw PriceListException::customerAlreadyAssigned($customer->name, $existing->priceList->name);
        }

        if ($customer->deleted || ! $customer->is_active) {
            throw PriceListException::customerUnavailable($customer->name);
        }

        $assignment = PriceListCustomer::query()->create(['price_list_id' => $list->getKey(), 'customer_uuid' => $customer->uuid]);
        $list->touch();

        return $assignment;
    }
}
