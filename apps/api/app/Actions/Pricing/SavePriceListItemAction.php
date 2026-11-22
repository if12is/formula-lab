<?php

namespace App\Actions\Pricing;

use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Exceptions\Domain\PriceListException;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\Pricing\PricingRuleService;
use App\Support\DecimalNormalizer;

/**
 * Adds a product to a price list, or changes how an existing line is priced. A line holds a rule key or an override (price,
 * source, confidence), never both and never a computed price. The rule key must be one of config/pricing.php; whether the
 * rule is complete is the engine's question and is answered when the price is worked out.
 */
final class SavePriceListItemAction
{
    public function __construct(private readonly PricingRuleService $rules) {}

    /**
     * @param  array{mode: string, rule_key?: string|null, override_price?: string|null, override_source_id?: string|null, override_confidence?: string|null}  $data
     *
     * @throws PriceListException When the product belongs to another tenant, is already on the list (adding) or the rule is unknown.
     */
    public function __invoke(PriceList $list, Formula $formula, array $data, ?PriceListItem $item = null): PriceListItem
    {
        if ($formula->tenant_id !== $list->tenant_id) {
            throw PriceListException::crossTenant();
        }
        if ($item === null && $list->items()->where('formula_id', $formula->getKey())->exists()) {
            throw PriceListException::productAlreadyOnList($formula->code);
        }

        $mode = PriceListMode::from($data['mode']);
        if ($mode === PriceListMode::Rule && ! $this->rules->exists((string) ($data['rule_key'] ?? ''))) {
            throw PriceListException::unknownRule((string) ($data['rule_key'] ?? ''));
        }

        $attributes = $mode === PriceListMode::Rule
            ? ['mode' => $mode, 'rule_key' => $data['rule_key'], 'override_price' => null, 'override_source_id' => null, 'override_confidence' => null]
            : [
                'mode' => $mode,
                'rule_key' => null,
                'override_price' => DecimalNormalizer::normalize((string) $data['override_price']),
                'override_source_id' => $data['override_source_id'],
                'override_confidence' => Confidence::from((string) $data['override_confidence']),
            ];

        $item ??= new PriceListItem(['price_list_id' => $list->getKey(), 'formula_id' => $formula->getKey()]);
        $item->fill($attributes)->save();
        $list->touch();

        return $item;
    }
}
