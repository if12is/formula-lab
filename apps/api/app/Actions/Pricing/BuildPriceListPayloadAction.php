<?php

namespace App\Actions\Pricing;

use App\Exceptions\Domain\PriceListException;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use App\Services\Pricing\PriceListPricingService;
use App\Support\DTOs\PriceListPayload;

/**
 * The body Sanad receives for a price list (contract 8.6): uuid, name, currency, is_active, a full snapshot of the items
 * and the customers to put on or take off the list. Every price in it is the engine's (script `price-list-lines`), as a
 * two-place decimal string; a product the last publish sent and the list no longer holds goes out with `price: null` so
 * Sanad drops it. Nothing is built for a list that is not ready: products not yet published to Sanad, or lines the engine
 * cannot price, stop the publish with a message naming them.
 */
final class BuildPriceListPayloadAction
{
    public function __construct(private readonly PriceListPricingService $pricing) {}

    /**
     * @throws PriceListException When the list is too long for one request, holds products Sanad does not know yet, or has lines without a price.
     */
    public function __invoke(PriceList $list): PriceListPayload
    {
        $items = $list->items()->with('formula.unitCostSource', 'overrideSource')->get()->sortBy(fn (PriceListItem $item): string => $item->formula->code)->values();
        $max = (int) config('sanad.price_lists.max_items_per_request');
        $removed = array_values(array_diff((array) $list->published_items, $items->map(fn (PriceListItem $item): ?string => $item->formula->sanad_product_uuid)->all()));

        if ($items->count() + count($removed) > $max) {
            throw PriceListException::tooManyItems($items->count() + count($removed), $max);
        }

        $unpublished = $items->filter(fn (PriceListItem $item): bool => $item->formula->sanad_product_uuid === null);
        if ($unpublished->isNotEmpty()) {
            throw PriceListException::productsNotPublished($unpublished->map(fn (PriceListItem $item): string => $item->formula->code)->all());
        }

        $answers = $this->pricing->evaluate($items);
        $incomplete = $items->filter(fn (PriceListItem $item): bool => ($answers[$item->getKey()]['ok'] ?? false) !== true);
        if ($incomplete->isNotEmpty()) {
            throw PriceListException::pricesIncomplete($incomplete->map(fn (PriceListItem $item): string => $item->formula->code)->all());
        }

        $rows = $items
            ->map(fn (PriceListItem $item): array => ['product_uuid' => $item->formula->sanad_product_uuid, 'price' => (string) $answers[$item->getKey()]['price']])
            ->sortBy('product_uuid')
            ->values();
        $dropped = collect($removed)->sort()->map(fn (string $uuid): array => ['product_uuid' => $uuid, 'price' => null]);

        $customers = $list->customers()->get()->map(fn (PriceListCustomer $row): string => $row->customer_uuid)->sort()->values()->all();
        $unassign = array_values(array_diff((array) $list->published_customers, $customers));

        $body = [
            'uuid' => $list->getKey(),
            'name' => $list->name,
            'currency' => $list->currency,
            'is_active' => $list->is_active,
            'items' => $rows->concat($dropped)->values()->all(),
            // Sanad does not let an inactive list receive customers; they stay assigned and are priced as if they had no list.
            'assign_customers' => $list->is_active ? $customers : [],
            'unassign_customers' => $unassign,
        ];

        return new PriceListPayload($body, $rows->pluck('product_uuid')->all(), $customers);
    }
}
