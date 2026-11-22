<?php

namespace App\Services\Pricing;

use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Enums\SourceKind;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;

/** Shapes a price list for its screens: the header, a product line with its engine input, a merchant row, and the form options. */
final class PriceListDetailService
{
    public function __construct(
        private readonly PricingRuleService $rules,
        private readonly PriceListPricingService $pricing,
    ) {}

    /**
     * The header of both tabs: the list's identity, where it stands with Sanad and whether it changed since the last publish.
     *
     * @return array<string, mixed>
     */
    public function header(PriceList $list): array
    {
        $lastChange = max(
            $list->updated_at,
            $list->items()->max('updated_at') ?? $list->updated_at,
            $list->customers()->max('updated_at') ?? $list->updated_at,
        );

        return [
            'id' => $list->getKey(),
            'name' => $list->name,
            'currency' => $list->currency,
            'is_active' => $list->is_active,
            'sync_status' => $list->sync_status->value,
            'sync_detail' => $list->sync_detail,
            'published_at' => $list->published_at?->toIso8601String(),
            'changed_since_publish' => $list->published_at !== null && $lastChange > $list->published_at,
            'items_count' => $list->items()->count(),
            'customers_count' => $list->customers()->count(),
        ];
    }

    /**
     * One product line: how it is priced and the engine input behind it. The screen runs that input through the engine for
     * the price, the margin and the explain tree; nothing is computed here.
     *
     * @return array<string, mixed>
     */
    public function itemRow(PriceListItem $item): array
    {
        $formula = $item->formula;
        $rule = $item->rule_key === null ? null : collect($this->rules->all())->firstWhere('key', $item->rule_key);

        return [
            'id' => $item->getKey(),
            'formula_id' => $formula->getKey(),
            'code' => $formula->code,
            'name' => $formula->name,
            'product_published' => $formula->sanad_product_uuid !== null,
            'mode' => $item->mode->value,
            'rule_key' => $item->rule_key,
            'rule_label' => $rule['label'] ?? $item->rule_key,
            'override' => $item->mode === PriceListMode::Override ? [
                'price' => $item->override_price,
                'source_id' => $item->override_source_id,
                'source_title' => $item->overrideSource?->title,
                'confidence' => $item->override_confidence?->value,
            ] : null,
            'unit_cost' => $this->unitCostRow($formula),
            'engine' => $this->pricing->lineInput($item),
        ];
    }

    /** @return array{id: string, customer_uuid: string, name: string, name_en: ?string, code: ?string, active: bool, deleted: bool, wholesale: bool, in_sanad: string} */
    public function customerRow(PriceListCustomer $row, string $listId): array
    {
        $sanadList = $row->getAttribute('sanad_price_list_uuid');

        return [
            'id' => $row->getKey(),
            'customer_uuid' => $row->customer_uuid,
            'name' => (string) $row->getAttribute('customer_name'),
            'name_en' => $row->getAttribute('customer_name_en'),
            'code' => $row->getAttribute('customer_code'),
            'active' => (bool) $row->getAttribute('customer_active'),
            'deleted' => (bool) $row->getAttribute('customer_deleted'),
            'wholesale' => (bool) $row->getAttribute('customer_wholesale'),
            'in_sanad' => $sanadList === null ? 'none' : ($sanadList === $listId ? 'this' : 'other'),
        ];
    }

    /**
     * The form options and addresses of the line modal, the cost modal and the selectors.
     *
     * @return array<string, mixed>
     */
    public function formProps(): array
    {
        return [
            'rules' => $this->rules->all(),
            'modeOptions' => PriceListMode::options(),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => [
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'products' => route('price-lists.products.search', [], false),
                'customers' => route('price-lists.customers.search', [], false),
            ],
        ];
    }

    /** @return array{value: string, source_id: string, source_title: string, confidence: string}|null */
    public function unitCostRow(Formula $formula): ?array
    {
        $formula->loadMissing('unitCostSource');

        return $formula->unit_cost === null ? null : [
            'value' => $formula->unit_cost,
            'source_id' => (string) $formula->unit_cost_source_id,
            'source_title' => (string) $formula->unitCostSource?->title,
            'confidence' => (string) $formula->unit_cost_confidence?->value,
        ];
    }
}
