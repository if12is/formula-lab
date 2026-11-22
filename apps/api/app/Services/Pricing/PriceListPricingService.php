<?php

namespace App\Services\Pricing;

use App\Enums\PriceListMode;
use App\Models\Formula;
use App\Models\PriceListItem;
use App\Support\Engine\EngineRunner;
use Illuminate\Support\Collection;

/**
 * The bridge between the price list rows and the engine's priceListLine. It only shapes inputs (a sourced unit cost, a rule or
 * an override, each with its source and confidence) and hands them to the engine: the screens run the same input in the
 * browser, the publish runs it here through the `price-list-lines` script. No price or margin is ever worked out in PHP.
 */
final class PriceListPricingService
{
    public function __construct(
        private readonly EngineRunner $engine,
        private readonly PricingRuleService $rules,
    ) {}

    /**
     * The engine input of one line: `{ key, unitCost?, spec? }`. A part that is not there is left out, so the engine lists it as missing.
     *
     * @return array<string, mixed>
     */
    public function lineInput(PriceListItem $item): array
    {
        $item->loadMissing('formula.unitCostSource', 'overrideSource');
        $spec = match ($item->mode) {
            PriceListMode::Rule => array_filter(['mode' => 'rule', 'rule' => $this->rules->engineRule((string) $item->rule_key)], fn (mixed $part): bool => $part !== null),
            PriceListMode::Override => array_filter(['mode' => 'override', 'price' => $this->overridePrice($item)], fn (mixed $part): bool => $part !== null),
        };

        return array_filter([
            'key' => $item->getKey(),
            'unitCost' => $this->unitCost($item->formula),
            'spec' => $spec,
        ], fn (mixed $part): bool => $part !== null);
    }

    /**
     * The unit cost of a product as a sourced EGP value, or null when it has none (the engine then reports it missing).
     *
     * @return array<string, string>|null
     */
    public function unitCost(Formula $formula): ?array
    {
        $formula->loadMissing('unitCostSource');
        $source = $formula->unitCostSource;

        if ($formula->unit_cost === null || $source === null || $formula->unit_cost_confidence === null) {
            return null;
        }

        return array_filter([
            'value' => $formula->unit_cost,
            'unit' => 'EGP',
            'sourceId' => $source->getKey(),
            'confidence' => $formula->unit_cost_confidence->value,
            'validFrom' => $source->retrieved_at?->toDateString(),
        ], fn (?string $part): bool => $part !== null);
    }

    /**
     * Runs the engine over the lines. The answer of each line is the script's: `ok` with price and margins, or `missing`.
     *
     * @param  Collection<int, PriceListItem>  $items
     * @return array<string, array<string, mixed>> answers keyed by item id
     */
    public function evaluate(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $answer = $this->engine->run('price-list-lines', ['lines' => $items->map(fn (PriceListItem $item): array => $this->lineInput($item))->values()->all()]);

        return collect((array) ($answer['lines'] ?? []))->keyBy('key')->all();
    }

    /** @return array<string, string>|null */
    private function overridePrice(PriceListItem $item): ?array
    {
        $source = $item->overrideSource;

        if ($item->override_price === null || $source === null || $item->override_confidence === null) {
            return null;
        }

        return array_filter([
            'value' => $item->override_price,
            'unit' => 'EGP',
            'sourceId' => $source->getKey(),
            'confidence' => $item->override_confidence->value,
            'validFrom' => $source->retrieved_at?->toDateString(),
        ], fn (?string $part): bool => $part !== null);
    }
}
