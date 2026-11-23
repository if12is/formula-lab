<?php

namespace App\Services\Pricing;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\Costing\CostInputService;
use App\Services\Currency\ExchangeRateLookupService;
use App\Services\Tax\TaxRegimeService;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;
use App\Support\Engine\GapText;

/**
 * S-13: the price ladder of a product. It gathers what the engine's waterfall reads (the product's sourced unit cost, its tax setting with the dated
 * regimes, the margins typed on the screen, the cost report of its newest formula version for the USD exposure and the dollar rate in force) and
 * words what comes back. Cost -> shelf, shelf -> maximum cost and the dollar's effect are all `scripts/price-ladder.ts`; nothing is computed here.
 */
final class PriceLadderService
{
    private const RATE_FIELDS = [
        'margin_basis' => 'marginBasis',
        'factory_margin_pct' => 'factoryMarginPct',
        'distribution_cost_per_unit' => 'distributionCostPerUnit',
        'wholesaler_margin_pct' => 'wholesalerMarginPct',
        'retailer_margin_pct' => 'retailerMarginPct',
        'returns_pct' => 'returnsPct',
        'promo_pct' => 'promoPct',
    ];

    public function __construct(
        private readonly EngineRunner $engine,
        private readonly PriceListPricingService $pricing,
        private readonly TaxRegimeService $tax,
        private readonly CostInputService $costInputs,
        private readonly ExchangeRateLookupService $exchangeRates,
    ) {}

    /**
     * What the screen shows about the chosen product before anything is calculated.
     *
     * @return array<string, mixed>
     */
    public function product(Formula $formula): array
    {
        $formula->loadMissing('unitCostSource');
        $unitCost = $this->pricing->unitCost($formula);
        $rate = $this->exchangeRates->latest('USD', (string) $formula->tenant_id);

        return [
            'id' => $formula->getKey(),
            'code' => $formula->code,
            'name' => $formula->name,
            'unit_cost' => $unitCost === null ? null : [
                'value' => DecimalNormalizer::normalize($unitCost['value']),
                'source_id' => $formula->unit_cost_source_id,
                'source_title' => $formula->unitCostSource?->title,
                'confidence' => $unitCost['confidence'],
                'valid_from' => $unitCost['validFrom'] ?? null,
            ],
            'tax' => ['set' => $formula->tax_mode !== null, 'label' => $this->taxLabel($formula)],
            'usd_rate' => $rate === null ? null : ['rate' => DecimalNormalizer::normalize($rate['rate']), 'date' => $rate['rate_date'], 'source' => $rate['source_title'], 'confidence' => $rate['confidence']],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  The validated request: typed rates, `shelf_price`, `fill_kg`, `non_formula_cost_per_unit`, `fx_changes_pct`.
     * @return array<string, mixed>
     */
    public function calculate(Formula $formula, array $input): array
    {
        $rates = $this->rates($input);
        $tax = $this->tax->engineTax($formula);
        $unitCost = $this->pricing->unitCost($formula);
        $shelf = $this->filled($input, 'shelf_price');
        $fillKg = $this->filled($input, 'fill_kg');
        $changes = array_values(array_filter(array_map(fn (mixed $value): string => (string) $value, $input['fx_changes_pct'] ?? []), fn (string $value): bool => $value !== ''));
        $rate = $this->exchangeRates->latest('USD', (string) $formula->tenant_id);

        $request = ['forward' => [...$rates, ...($unitCost === null ? [] : ['unitCost' => $unitCost]), 'tax' => $tax]];
        if ($shelf !== null) {
            $request['reverse'] = array_filter([
                ...$rates,
                'shelfPriceInclVat' => $shelf,
                'fillKg' => $fillKg,
                'nonFormulaCostPerUnit' => $this->filled($input, 'non_formula_cost_per_unit'),
                'tax' => $tax,
            ], fn (mixed $part): bool => $part !== null);
        }
        if ($changes !== []) {
            $version = $this->costVersion($formula);
            $request['fx'] = array_filter([
                'costReport' => $version === null ? null : $this->costInputs->forVersions(collect([$version]))[$version->getKey()]['input'],
                'fillKg' => $fillKg,
                'changesPct' => $changes,
                'usdRate' => $rate === null ? null : DecimalNormalizer::normalize($rate['rate']),
            ], fn (mixed $part): bool => $part !== null);
        }

        $answer = $this->engine->run('price-ladder', $request);

        return [
            'forward' => $this->forward($answer['forward'] ?? null),
            'reverse' => $this->reverse($answer['reverse'] ?? null),
            'fx' => $this->fx($answer['fx'] ?? null, $changes !== [] && ! isset($request['fx']['costReport'])),
        ];
    }

    /** @return array<string, mixed>|null */
    private function forward(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }
        if (! $result['ok']) {
            return ['ok' => false, 'missing' => GapText::all($result['missing'], [], 'price_ladder.fields'), 'warnings' => $result['warnings']];
        }
        $value = $result['value'];

        return [
            'ok' => true,
            'steps' => array_map(fn (array $step): array => ['key' => $step['key'], 'label' => __('price_ladder.steps.'.$step['key']), 'traced' => $step['value']], $value['steps']),
            'consumer_price' => $value['consumerPrice'],
            'tax' => $value['tax'],
            'vat_amount' => $value['vatAmount'],
            'warnings' => $result['warnings'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function reverse(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }
        if (! $result['ok']) {
            return ['ok' => false, 'missing' => GapText::all($result['missing'], [], 'price_ladder.fields'), 'warnings' => $result['warnings']];
        }
        $value = $result['value'];

        return [
            'ok' => true,
            'steps' => array_map(fn (array $step): array => ['key' => $step['key'], 'label' => __('price_ladder.reverse_steps.'.substr($step['key'], strlen('pricing.waterfall.step.'))), 'value' => $step['value']], $value['steps']),
            'max_unit_cost' => $value['maxUnitCost'],
            'max_cost_per_kg' => $value['maxCostPerKg'],
            'max_formula_cost_per_kg' => $value['maxFormulaCostPerKg'],
            'tax' => ['state' => $value['taxState'], 'rate_pct' => $value['vatRatePct']],
            'warnings' => $result['warnings'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function fx(?array $result, bool $noCostReport): ?array
    {
        if ($result === null) {
            return null;
        }
        $exposure = $result['exposure'];
        $sensitivity = $result['sensitivity'];

        return [
            'exposure' => $exposure === null
                ? ['ok' => false, 'missing' => GapText::all([['property' => 'costReport', 'reason' => 'pricing.ladder.usd_exposure_unavailable']], [], 'price_ladder.fields')]
                : ($exposure['ok'] ? ['ok' => true, ...$exposure['value']] : ['ok' => false, 'missing' => GapText::all($exposure['missing'], [], 'price_ladder.fields')]),
            'sensitivity' => $sensitivity === null
                ? null
                : ($sensitivity['ok']
                    ? ['ok' => true, ...$sensitivity['value'], 'rows' => array_map(fn (array $row): array => [...$row, 'missing' => GapText::all($row['missing'], [], 'price_ladder.fields')], $sensitivity['value']['rows'])]
                    : ['ok' => false, 'missing' => GapText::all($sensitivity['missing'], [], 'price_ladder.fields')]),
            'no_formula_version' => $noCostReport,
        ];
    }

    /** The version whose materials the dollar exposure is read from: the newest approved one, else the newest version. */
    private function costVersion(Formula $formula): ?FormulaVersion
    {
        $versions = $formula->versions()->reorder()->orderByDesc('version');

        return (clone $versions)->where('status', FormulaStatus::Approved)->first() ?? $versions->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function rates(array $input): array
    {
        $rates = [];
        foreach (self::RATE_FIELDS as $field => $name) {
            $value = $this->filled($input, $field);
            if ($value !== null) {
                $rates[$name] = $field === 'margin_basis' ? $value : DecimalNormalizer::normalize($value);
            }
        }

        return $rates;
    }

    /** @param  array<string, mixed>  $input */
    private function filled(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function taxLabel(Formula $formula): string
    {
        $setting = $this->tax->settingOf($formula);
        $option = collect($this->tax->settingOptions())->firstWhere('value', $setting);

        return $option['label'] ?? __('price_ladder.tax_not_set');
    }
}
