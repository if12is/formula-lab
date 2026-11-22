<?php

namespace App\Services\Pricing;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Models\Formula;
use App\Models\PriceListCustomer;
use App\Models\SanadCustomer;

/** The searchable options of the price list selectors: Sanad customers (merchants) and products (formulas). */
final class PriceListOptionService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly PriceListPricingService $pricing,
    ) {}

    /**
     * Active, not archived customers matching the text by name, English name or code.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function customers(string $term): array
    {
        $tenantId = ($this->ensureDefaultTenant)()->getKey();
        $assigned = PriceListCustomer::query()->with('priceList')->get()->keyBy('customer_uuid');

        return SanadCustomer::query()
            ->where('tenant_id', $tenantId)
            ->where('deleted', false)
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('code', 'ilike', $like);
            }))
            ->orderBy('name')
            ->limit((int) config('pricing.search_limit'))
            ->get()
            ->map(fn (SanadCustomer $customer): array => [
                'value' => $customer->uuid,
                'label' => $customer->name,
                'description' => trim(($customer->code ?? '').' '.($assigned->has($customer->uuid) ? __('price_lists.customers.on_list', ['list' => $assigned[$customer->uuid]->priceList->name]) : '')),
            ])
            ->all();
    }

    /**
     * Products (formulas) matching the text by code or name; a product not published to Sanad yet says so.
     *
     * @return list<array{value: string, label: string, description: string, unit_cost: array<string, string>|null, unit_cost_source_title: string|null}>
     */
    public function products(string $term): array
    {
        $tenantId = ($this->ensureDefaultTenant)()->getKey();

        return Formula::query()
            ->where('tenant_id', $tenantId)
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like);
            }))
            ->orderBy('code')
            ->limit((int) config('pricing.search_limit'))
            ->with('unitCostSource')
            ->get()
            ->map(fn (Formula $formula): array => [
                'value' => $formula->getKey(),
                'label' => $formula->code.' · '.$formula->name,
                'description' => $formula->sanad_product_uuid === null ? __('price_lists.items.not_published') : __('price_lists.items.published_in_sanad'),
                'unit_cost' => $this->pricing->unitCost($formula),
                'unit_cost_source_title' => $formula->unitCostSource?->title,
            ])
            ->all();
    }
}
