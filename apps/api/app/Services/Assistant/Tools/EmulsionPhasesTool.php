<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;

/**
 * The water, oil and cool-down phase sums of a saved formula version (M-01), by the engine's phase check; a q.s. line is balanced by the engine
 * first. A line with no phase, an unknown phase, a bad addition temperature or phase sums that are not 100 are gaps in `missing`, so a formula
 * is never called phased when it is not.
 */
final class EmulsionPhasesTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(private readonly EngineRunner $engine) {}

    public function name(): string
    {
        return 'emulsion_phases';
    }

    public function description(): string
    {
        return 'Deterministic engine: the sum of each process phase (water, oil, cool_down) of a saved formula version (formulaVersionId), the lines without a phase and the total, with the deviation from 100. A formula with no phases, a line with no phase or phase sums that are not 100 are reported in missing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formulaVersionId' => ['type' => 'string', 'description' => 'The id of a saved formula version.'],
            ],
            'required' => ['formulaVersionId'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaComposition];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['formulaVersionId' => ['required', 'uuid']]);
        $version = FormulaVersion::query()->with('lines')->find($valid['formulaVersionId']);
        if ($version === null) {
            return $this->missing([['property' => 'formulaVersion', 'reason' => 'formula_version.not_found']]);
        }

        $answer = $this->engine->run('assistant', ['op' => 'formula-phases', 'input' => ['lines' => $version->lines->map(fn (FormulaLine $line): array => array_filter([
            'materialId' => $line->trade_material_id,
            'percentWW' => $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww),
            'qs' => $line->is_qs ?: null,
            'phase' => $line->phase,
            'additionTempC' => $line->addition_temp_c === null ? null : DecimalNormalizer::normalize($line->addition_temp_c),
        ], fn (mixed $part): bool => $part !== null))->values()->all()]]);

        if (($answer['ok'] ?? false) !== true) {
            return $this->missing((array) ($answer['missing'] ?? []), [], (array) ($answer['warnings'] ?? []));
        }

        return $this->answer((array) $answer['value'], (array) ($answer['warnings'] ?? []));
    }
}
