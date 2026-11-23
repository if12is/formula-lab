<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Costing\CostReportEngineService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;

/** The material cost of a saved formula version, or of a what-if list of lines, by the engine's own cost report (the formula editor's figure). */
final class CalculateFormulaTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(private readonly CostReportEngineService $costReport) {}

    public function name(): string
    {
        return 'calculate_formula';
    }

    public function description(): string
    {
        return 'Deterministic engine: the material cost per kg of a saved formula version (formulaVersionId) or of a what-if list of lines (materialId and percentWW as decimal strings). Returns each line cost with the price quote used (source, confidence, date), the total, and whatever is missing (a material with no usable price is reported, never costed as zero). Give either formulaVersionId or lines.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formulaVersionId' => ['type' => 'string', 'description' => 'The id of a saved formula version.'],
                'lines' => [
                    'type' => 'array',
                    'description' => 'A what-if formula, percent w/w per material as decimal strings.',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['materialId' => ['type' => 'string'], 'percentWW' => ['type' => 'string']],
                        'required' => ['materialId', 'percentWW'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaComposition, AssistantDataCategory::FormulaCost];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, [
            'formulaVersionId' => ['nullable', 'uuid'],
            'lines' => ['nullable', 'array', 'min:1', 'max:60'],
            'lines.*' => ['array:materialId,percentWW'],
            'lines.*.materialId' => ['required', 'uuid'],
            'lines.*.percentWW' => ['required', 'string', 'regex:/^\d+(\.\d+)?$/'],
        ]);
        $versionId = $valid['formulaVersionId'] ?? null;
        $lines = $valid['lines'] ?? null;

        if (($versionId === null) === ($lines === null)) {
            return $this->missing([['property' => 'formulaVersionId_or_lines', 'reason' => 'input.exactly_one']]);
        }

        if ($versionId !== null) {
            $version = FormulaVersion::query()->find($versionId);
            if ($version === null) {
                return $this->missing([['property' => 'formulaVersion', 'reason' => 'formula_version.not_found']]);
            }
            $run = $this->costReport->run($version);
        } else {
            $materials = TradeMaterial::query()->with('substance')->whereIn('id', array_column($lines, 'materialId'))->get()->keyBy('id');
            $unknown = array_values(array_diff(array_column($lines, 'materialId'), $materials->keys()->all()));
            if ($unknown !== []) {
                return $this->missing(array_map(fn (string $id): array => ['materialId' => $id, 'property' => 'material', 'reason' => 'material.not_found'], $unknown));
            }
            $run = $this->costReport->forLines(array_map(function (array $line) use ($materials): FormulaLine {
                $model = new FormulaLine(['trade_material_id' => $line['materialId'], 'percent_ww' => $line['percentWW'], 'is_qs' => false]);
                $model->setRelation('tradeMaterial', $materials[$line['materialId']]);

                return $model;
            }, $lines));
        }

        $report = $run['report'];
        $names = array_map(fn (TradeMaterial $material): string => $material->trade_name, $run['materials']);
        $data = [
            'lines' => array_map(function (array $line) use ($names, $run): array {
                $quote = $line['quoteIndex'] === null ? null : ($run['quotes'][$line['materialId']][$line['quoteIndex']] ?? null);
                unset($line['trace'], $line['quoteIndex']);

                return [...$line, 'material' => $names[$line['materialId']] ?? null, 'quote' => $quote === null ? null : [
                    'amount' => $quote['amount'],
                    'currency' => $quote['currency'],
                    'quotedOn' => $quote['quoted_on'],
                    'source' => $quote['source_title'],
                    'sourceId' => $quote['source_id'],
                    'confidence' => $quote['confidence'],
                ]];
            }, (array) ($report['lines'] ?? [])),
            'total' => $report['total'] ?? null,
            'engineVersion' => $report['engineVersion'] ?? null,
        ];
        $missing = (array) ($report['missing'] ?? []);

        return ($report['ok'] ?? false) === true && $missing === []
            ? $this->answer($data, (array) ($report['warnings'] ?? []))
            : $this->missing($missing, $data, (array) ($report['warnings'] ?? []));
    }
}
