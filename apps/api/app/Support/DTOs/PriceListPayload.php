<?php

namespace App\Support\DTOs;

use App\Support\RequestFingerprint;

/** The `price_list_upsert` body of contract 8.6 plus what it says about the list (to remember for the next removals). */
final readonly class PriceListPayload
{
    /**
     * @param  array<string, mixed>  $body  uuid, name, currency, is_active, items, assign_customers, unassign_customers
     * @param  list<string>  $itemUuids  products that carry a price in this payload
     * @param  list<string>  $customerUuids  customers that are on the list in this payload
     */
    public function __construct(
        public array $body,
        public array $itemUuids,
        public array $customerUuids,
    ) {}

    /** Stable hash of the body: the same list content always has the same hash, so publishing twice sends once. */
    public function hash(): string
    {
        return RequestFingerprint::hash($this->body);
    }
}
