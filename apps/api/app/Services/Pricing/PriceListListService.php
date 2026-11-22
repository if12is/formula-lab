<?php

namespace App\Services\Pricing;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\PriceListMode;
use App\Enums\PriceListSyncStatus;
use App\Http\Filters\ListQuery;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the price list screens: the lists, a list's product lines and a list's merchants, each filtered, sorted and paged. */
final class PriceListListService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly PriceListDetailService $detail,
    ) {}

    /**
     * The whole `list` prop for /price-lists.
     *
     * @return array<string, mixed>
     */
    public function lists(Request $request): array
    {
        $tenantId = ($this->ensureDefaultTenant)()->getKey();
        $query = PriceList::query()->where('tenant_id', $tenantId)->withCount(['items', 'customers']);

        return ListQuery::for($query, $request)
            ->search(['name'], 'price_lists.search_placeholder')
            ->select('sync_status', PriceListSyncStatus::class, labelKey: 'price_lists.filters.sync_status')
            ->select('active', [
                ['value' => '1', 'labelKey' => 'price_lists.active.on'],
                ['value' => '0', 'labelKey' => 'price_lists.active.off'],
            ], labelKey: 'price_lists.filters.active', using: fn (Builder $q, string $value) => $q->where('is_active', $value === '1'))
            ->dateRange('updated', 'updated_at', 'price_lists.filters.updated')
            ->sortable(['name', 'updated' => 'updated_at', 'items' => 'items_count', 'merchants' => 'customers_count'], default: 'updated', direction: 'desc', labelPrefix: 'price_lists.sorts')
            ->perPage((int) config('pricing.list_per_page'), [10, 25, 50, 100])
            ->paginate(fn (PriceList $list): array => [
                'id' => $list->getKey(),
                'name' => $list->name,
                'is_active' => $list->is_active,
                'sync_status' => $list->sync_status->value,
                'items_count' => (int) $list->getAttribute('items_count'),
                'customers_count' => (int) $list->getAttribute('customers_count'),
                'published_at' => $list->published_at?->toIso8601String(),
                'updated_at' => $list->updated_at->toDateString(),
                'href' => route('price-lists.show', $list, false),
            ]);
    }

    /**
     * The `list` prop of a price list's product lines (with the engine input of each line, so the screen shows what the
     * engine says about it).
     *
     * @return array<string, mixed>
     */
    public function items(Request $request, PriceList $list): array
    {
        $derived = PriceListItem::query()
            ->select('price_list_items.*')
            ->selectRaw('formulas.code as formula_code, formulas.name as formula_name, (formulas.sanad_product_uuid is not null) as product_published')
            ->join('formulas', 'formulas.id', '=', 'price_list_items.formula_id')
            ->where('price_list_items.price_list_id', $list->getKey());

        return ListQuery::for(PriceListItem::query()->fromSub($derived, 'price_list_items')->with('formula.unitCostSource', 'overrideSource'), $request)
            ->search(['formula_code', 'formula_name'], 'price_lists.items.search_placeholder')
            ->select('mode', PriceListMode::class, labelKey: 'price_lists.items.filters.mode')
            ->select('published', [
                ['value' => '1', 'labelKey' => 'price_lists.items.published.yes'],
                ['value' => '0', 'labelKey' => 'price_lists.items.published.no'],
            ], labelKey: 'price_lists.items.filters.published', using: fn (Builder $q, string $value) => $q->where('product_published', $value === '1'))
            ->sortable(['code' => 'formula_code', 'name' => 'formula_name', 'updated' => 'updated_at'], default: 'code', labelPrefix: 'price_lists.items.sorts')
            ->perPage((int) config('pricing.list_per_page'), [10, 25, 50, 100])
            ->paginate(fn (PriceListItem $item): array => $this->detail->itemRow($item));
    }

    /**
     * The `list` prop of a price list's merchants, each with where Sanad itself has the customer.
     *
     * @return array<string, mixed>
     */
    public function customers(Request $request, PriceList $list): array
    {
        $derived = PriceListCustomer::query()
            ->select('price_list_customers.*')
            ->selectRaw('c.name as customer_name, c.name_en as customer_name_en, c.code as customer_code, c.is_active as customer_active, c.deleted as customer_deleted, c.is_wholesale as customer_wholesale, c.price_list_uuid as sanad_price_list_uuid')
            ->join('sanad_customers_cache as c', 'c.uuid', '=', 'price_list_customers.customer_uuid')
            ->where('price_list_customers.price_list_id', $list->getKey());
        $listId = $list->getKey();

        return ListQuery::for(PriceListCustomer::query()->fromSub($derived, 'price_list_customers'), $request)
            ->search(['customer_name', 'customer_name_en', 'customer_code'], 'price_lists.customers.search_placeholder')
            ->select('in_sanad', [
                ['value' => 'this', 'labelKey' => 'price_lists.customers.in_sanad.this'],
                ['value' => 'other', 'labelKey' => 'price_lists.customers.in_sanad.other'],
                ['value' => 'none', 'labelKey' => 'price_lists.customers.in_sanad.none'],
            ], labelKey: 'price_lists.customers.filters.in_sanad', using: fn (Builder $q, string $value) => match ($value) {
                'this' => $q->where('sanad_price_list_uuid', $listId),
                'other' => $q->where('sanad_price_list_uuid', '<>', $listId),
                default => $q->whereNull('sanad_price_list_uuid'),
            })
            ->select('wholesale', [
                ['value' => '1', 'labelKey' => 'price_lists.customers.wholesale.yes'],
                ['value' => '0', 'labelKey' => 'price_lists.customers.wholesale.no'],
            ], labelKey: 'price_lists.customers.filters.wholesale', using: fn (Builder $q, string $value) => $q->where('customer_wholesale', $value === '1'))
            ->sortable(['name' => 'customer_name', 'code' => 'customer_code', 'added' => 'created_at'], default: 'name', labelPrefix: 'price_lists.customers.sorts')
            ->perPage((int) config('pricing.list_per_page'), [10, 25, 50, 100])
            ->paginate(fn (PriceListCustomer $row): array => $this->detail->customerRow($row, $listId));
    }
}
