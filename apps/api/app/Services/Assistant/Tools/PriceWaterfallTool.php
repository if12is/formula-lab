<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\Formula;
use App\Services\Pricing\PriceLadderService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use Illuminate\Validation\Rule;

/**
 * The price ladder of a product (S-13), cost to shelf (`forward`) or shelf back to the maximum cost (`reverse`), by the engine's price-ladder
 * script. The margins, distribution cost and the shelf price are what the person typed; the engine lists any it lacks. The tax and the unit
 * cost are the product's own sourced settings.
 */
final class PriceWaterfallTool implements AssistantTool
{
    use BuildsToolAnswers;

    private const DIRECTIONS = ['forward', 'reverse'];

    private const BASES = ['markup_on_cost', 'margin_on_price'];

    /** Input key => the key the ladder service reads. */
    private const RATES = [
        'marginBasis' => 'margin_basis',
        'factoryMarginPct' => 'factory_margin_pct',
        'distributionCostPerUnit' => 'distribution_cost_per_unit',
        'wholesalerMarginPct' => 'wholesaler_margin_pct',
        'retailerMarginPct' => 'retailer_margin_pct',
        'returnsPct' => 'returns_pct',
        'promoPct' => 'promo_pct',
        'fillKg' => 'fill_kg',
        'nonFormulaCostPerUnit' => 'non_formula_cost_per_unit',
    ];

    public function __construct(private readonly PriceLadderService $ladder) {}

    public function name(): string
    {
        return 'price_waterfall';
    }

    public function description(): string
    {
        return 'Deterministic engine: the price ladder of a product (formulaId). direction forward walks unit cost to factory, wholesaler, retailer and shelf price with VAT; direction reverse walks a shelfPriceEGP back to the maximum unit cost and formula cost per kg. Margins and costs are the ones the user typed (decimal strings); any the user did not give is reported in missing, never assumed.';
    }

    public function inputSchema(): array
    {
        $decimal = fn (string $about): array => ['type' => 'string', 'description' => $about.' A decimal string.'];

        return [
            'type' => 'object',
            'properties' => [
                'formulaId' => ['type' => 'string', 'description' => 'The id of the product (formula).'],
                'direction' => ['type' => 'string', 'enum' => self::DIRECTIONS],
                'marginBasis' => ['type' => 'string', 'enum' => self::BASES, 'description' => 'Whether the margins are a markup on cost or a margin on price.'],
                'factoryMarginPct' => $decimal('Factory margin %.'),
                'distributionCostPerUnit' => $decimal('Distribution cost per unit, EGP.'),
                'wholesalerMarginPct' => $decimal('Wholesaler margin %.'),
                'retailerMarginPct' => $decimal('Retailer margin %.'),
                'returnsPct' => $decimal('Returns allowance %.'),
                'promoPct' => $decimal('Promotion allowance %.'),
                'shelfPriceEGP' => $decimal('The shelf price including VAT, for direction reverse.'),
                'fillKg' => $decimal('Fill of one unit in kg, for direction reverse.'),
                'nonFormulaCostPerUnit' => $decimal('Packaging and other cost per unit that is not the formula, EGP, for direction reverse.'),
            ],
            'required' => ['formulaId', 'direction'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaCost];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $decimal = ['nullable', 'string', 'regex:/^\d+(\.\d+)?$/'];
        $valid = $this->validated($input, [
            'formulaId' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(self::DIRECTIONS)],
            'marginBasis' => ['nullable', Rule::in(self::BASES)],
            'factoryMarginPct' => $decimal,
            'distributionCostPerUnit' => $decimal,
            'wholesalerMarginPct' => $decimal,
            'retailerMarginPct' => $decimal,
            'returnsPct' => $decimal,
            'promoPct' => $decimal,
            'shelfPriceEGP' => $decimal,
            'fillKg' => $decimal,
            'nonFormulaCostPerUnit' => $decimal,
        ]);

        $formula = Formula::query()->find($valid['formulaId']);
        if ($formula === null) {
            return $this->missing([['property' => 'formula', 'reason' => 'formula.not_found']]);
        }

        $reverse = $valid['direction'] === 'reverse';
        if ($reverse && ! isset($valid['shelfPriceEGP'])) {
            return $this->missing([['property' => 'shelfPriceEGP', 'reason' => 'input.missing']]);
        }

        $request = [];
        foreach (self::RATES as $key => $field) {
            if (isset($valid[$key])) {
                $request[$field] = $valid[$key];
            }
        }
        if ($reverse) {
            $request['shelf_price'] = $valid['shelfPriceEGP'];
        }

        $answer = $this->ladder->calculate($formula, $request)[$reverse ? 'reverse' : 'forward'];
        if ($answer === null || ! ($answer['ok'] ?? false)) {
            return $this->missing($this->plainGaps((array) ($answer['missing'] ?? [])), [], (array) ($answer['warnings'] ?? []));
        }

        $warnings = (array) $answer['warnings'];
        unset($answer['ok'], $answer['warnings']);

        return $this->answer([
            'product' => $formula->code,
            'direction' => $valid['direction'],
            ...$this->camelKeys($answer),
        ], $warnings);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function camelKeys(array $answer): array
    {
        $out = [];
        foreach ($answer as $key => $value) {
            $out[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = $value;
        }

        return $out;
    }
}
