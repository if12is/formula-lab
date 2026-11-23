<?php

namespace App\Services\Tiers;

use App\Enums\ProductTier;
use App\Models\Formula;
use App\Models\Source;
use App\Services\Tax\TaxRegimeService;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;
use App\Support\Engine\GapText;

/**
 * S-05: asks the optimizer (packages/optimizer, through EngineRunner) for the cheapest formula that meets a tier's profile, with what the
 * answer depends on: the constraints that bind, the effect of a price change of each material of the formula, and the target price
 * walked back to a maximum cost. PHP gathers what the optimizer reads (the profile, the materials with their quotes, the product's tax)
 * and words what it says (its message keys become Arabic or English text); it never decides a percentage, a cost or a limit.
 */
final class TierSolveService
{
    public function __construct(
        private readonly EngineRunner $engine,
        private readonly TierProfileService $profiles,
        private readonly OfferedMaterialService $offeredMaterials,
        private readonly TaxRegimeService $tax,
    ) {}

    /**
     * @param  array{qs_material_id?: string|null, scenario_pct?: string|null, reverse?: array<string, string|null>}  $input
     * @return array<string, mixed>
     */
    public function solve(Formula $formula, ProductTier $tier, array $input): array
    {
        $offered = $this->offeredMaterials->offered();
        $record = $this->profiles->find($formula->product_type, $tier);
        $profile = ['productType' => $formula->product_type, 'tier' => $tier->value, ...($record?->profile ?? $this->profiles->body([]))];
        $hasTarget = ($profile['targetRetailPrice']['value'] ?? null) !== null;

        $request = array_filter([
            'profile' => $profile,
            'profileSourceId' => $record?->source_id,
            'materials' => $offered['materials'],
            'fxRates' => $offered['fxRates'],
            'qsMaterialId' => $input['qs_material_id'] ?? null,
            'reversePricing' => $hasTarget ? $this->reverseInput($input['reverse'] ?? []) : null,
            'tax' => $hasTarget ? $this->tax->engineTax($formula) : null,
        ], fn (mixed $part): bool => $part !== null);

        $answer = $this->engine->run('tier-solve', $request);

        $scenarioPct = $input['scenario_pct'] ?? null;
        if (($answer['status'] ?? null) === 'optimal' && $scenarioPct !== null && $scenarioPct !== '') {
            $request['scenarios'] = array_map(fn (array $line): array => ['materialId' => $line['materialId'], 'changePct' => DecimalNormalizer::normalize($scenarioPct)], $answer['formula']);
            $answer = $this->engine->run('tier-solve', $request);
        }

        return $this->present($answer, $offered['names'], $record?->source);
    }

    /**
     * The reverse-pricing inputs typed on the screen (margins, distribution, fill), as the optimizer names them. Empty fields are left out so the
     * optimizer reports each as missing.
     *
     * @param  array<string, string|null>  $reverse
     * @return array<string, string>
     */
    private function reverseInput(array $reverse): array
    {
        $map = [
            'margin_basis' => 'marginBasis',
            'factory_margin_pct' => 'factoryMarginPct',
            'distribution_cost_per_unit' => 'distributionCostPerUnit',
            'wholesaler_margin_pct' => 'wholesalerMarginPct',
            'retailer_margin_pct' => 'retailerMarginPct',
            'non_formula_cost_per_unit' => 'nonFormulaCostPerUnit',
            'pack_fill_kg' => 'packFillKg',
        ];
        $out = [];
        foreach ($map as $field => $name) {
            $value = $reverse[$field] ?? null;
            if ($value !== null && $value !== '') {
                $out[$name] = $field === 'margin_basis' ? $value : DecimalNormalizer::normalize($value);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $answer  The optimizer's output plus `excluded` and `taxMissing`.
     * @param  array<string, string>  $names  Material names by id.
     * @return array<string, mixed>
     */
    private function present(array $answer, array $names, ?Source $source): array
    {
        $name = fn (string $id): string => $names[$id] ?? $id;
        $words = function (array $message) use ($name): string {
            $params = array_map('strval', (array) ($message['params'] ?? []));
            if (isset($params['materialId'])) {
                $params['materialId'] = $name($params['materialId']);
            }

            return $this->optimizerText((string) $message['key'], $params);
        };
        $sensitivity = array_map(fn (array $row): array => [
            ...$row,
            'material' => $name($row['materialId']),
            'formulaDelta' => array_map(fn (array $delta): array => [...$delta, 'material' => $name($delta['materialId'])], $row['formulaDelta']),
        ], $answer['sensitivity'] ?? []);

        return [
            'status' => $answer['status'],
            'ok' => $answer['ok'],
            'cost_per_kg' => $answer['costPerKg'],
            'formula' => array_map(fn (array $line): array => [...$line, 'material' => $name($line['materialId'])], $answer['formula']),
            'binding' => array_map(fn (array $row): array => [...$row, 'text' => $row['message'] === null ? $row['id'] : $words($row['message'])], $answer['binding']),
            'duals' => $answer['duals'] === null ? null : [
                'rows' => $answer['duals']['rows'],
                'reduced_costs' => array_map(fn (array $row): array => [...$row, 'material' => $name($row['materialId'])], $answer['duals']['reducedCosts']),
            ],
            'conflicts' => array_map(fn (array $conflict): array => ['id' => $conflict['id'], 'text' => $words($conflict)], $answer['conflicts']),
            'reverse' => $answer['reversePricing'] === null ? null : [
                'steps' => array_map(fn (array $step): array => ['key' => $step['key'], 'text' => $this->optimizerText($step['key'], ['value' => $step['value']]), 'value' => $step['value']], $answer['reversePricing']['steps']),
                'max_formula_cost_per_kg' => $answer['reversePricing']['maxFormulaCostPerKg'],
                'max_unit_cost' => $answer['reversePricing']['maxUnitCost'],
            ],
            'sensitivity' => $sensitivity,
            'missing' => GapText::all([...$answer['missing'], ...($answer['taxMissing'] ?? [])], $names, 'tiers.profile_fields'),
            'errors' => array_map($words, $answer['errors']),
            'warnings' => array_map(fn (string $warning): array => $this->warning($warning, $name), $answer['warnings']),
            'excluded' => array_map(fn (array $row): array => [
                'material' => $name($row['materialId']),
                'property' => $row['property'],
                'reason' => $row['reason'],
            ], $answer['excluded'] ?? []),
            'measured_requirements' => $answer['measuredRequirements'],
            'engine' => ['version' => $answer['optimizerVersion'], 'solver' => $answer['solver']['name'].' '.$answer['solver']['packageVersion'], 'input_hash' => $answer['inputHash'], 'output_hash' => $answer['outputHash']],
            'profile_source' => $source === null ? null : ['title' => $source->title, 'kind' => $source->kind],
        ];
    }

    /**
     * A warning string `key` or `key:detail` of the optimizer as `{ text }`, or `{ raw }` when it is one of the engine's own keys (the screen words those).
     *
     * @param  callable(string): string  $name
     * @return array{text: string|null, raw: string}
     */
    private function warning(string $raw, callable $name): array
    {
        if (! str_starts_with($raw, 'optimizer.')) {
            return ['text' => null, 'raw' => $raw];
        }
        [$key, $detail] = array_pad(explode(':', $raw, 2), 2, '');
        $detail = str_contains($key, 'scenario_invalid') || str_contains($key, 'function_bound') ? $name($detail) : $detail;

        return ['text' => $this->optimizerText($key, ['detail' => $detail]), 'raw' => $raw];
    }

    /** @param  array<string, string>  $params */
    private function optimizerText(string $key, array $params): string
    {
        $text = __($key, $params);

        return $text === $key ? $key : $text;
    }
}
