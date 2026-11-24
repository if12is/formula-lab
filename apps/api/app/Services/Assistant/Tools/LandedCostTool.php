<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;

/**
 * The delivered price per kg of materials: the newest usable price quote of each, as the engine's cost report reads it (the figure the
 * formula editor costs with), with its source, confidence and date. It is the engine's cost report of the material alone (100 % line), reused
 * from calculate_formula, so nothing is computed here. Freight, duties and clearance are NOT added by the engine yet (the landed-cost overlay,
 * G-07, is not built): the answer says so in a warning, and a material with no usable price is reported as missing, never as zero.
 */
final class LandedCostTool implements AssistantTool
{
    use BuildsToolAnswers;

    /** Most materials one call answers (validated here: a strict schema cannot carry maxItems). */
    private const MAX_MATERIALS = 10;

    public function __construct(private readonly CalculateFormulaTool $costing) {}

    public function name(): string
    {
        return 'landed_cost';
    }

    public function description(): string
    {
        return 'Deterministic engine: the delivered price per kg (EGP) of trade materials, from their newest usable price quote, with the quote source, confidence and date. Give materialIds (up to 10). A material with no usable price is reported in missing, never as zero. The freight, duty and clearance overlay is not recorded yet, so the price is the stored quote only: say so.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'materialIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the trade materials to price.'],
            ],
            'required' => ['materialIds'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaCost];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, [
            'materialIds' => ['required', 'array', 'min:1', 'max:'.self::MAX_MATERIALS],
            'materialIds.*' => ['uuid'],
        ]);

        $rows = [];
        $missing = [];
        foreach (array_values(array_unique($valid['materialIds'])) as $materialId) {
            $answer = ($this->costing)(['lines' => [['materialId' => $materialId, 'percentWW' => '100']]], $context);
            $line = $answer['data']['lines'][0] ?? null;
            $rows[] = [
                'materialId' => $materialId,
                'material' => $line['material'] ?? null,
                'priceEgpPerKg' => $answer['ok'] ? ($line['priceEgpPerKg'] ?? null) : null,
                'quote' => $answer['ok'] ? ($line['quote'] ?? null) : null,
            ];
            if (! $answer['ok']) {
                $missing = [...$missing, ...($answer['missing'] === [] ? [['materialId' => $materialId, 'property' => 'price', 'reason' => 'price.no_quote']] : $answer['missing'])];
            }
        }

        $data = ['materials' => $rows];
        $warnings = ['landed_cost.overlay_not_recorded'];

        return $missing === [] ? $this->answer($data, $warnings) : $this->missing($missing, $data, $warnings);
    }
}
