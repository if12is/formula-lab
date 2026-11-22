<?php

namespace App\Services\Costing;

use App\Models\DatasetVersion;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialOptionService;
use App\Support\DecimalNormalizer;
use App\Support\Engine\EngineRunner;

/**
 * The bridge between a formula version and the engine's cost report (the same `calculate` the formula editor shows). It only
 * shapes inputs: the version's lines, and each material's price quotes as engine candidates with the dated exchange rate of their
 * quote day. Nothing is costed in PHP. The quotes are kept in the order they were sent, so an index in the answer names one.
 */
final class CostReportEngineService
{
    public function __construct(
        private readonly EngineRunner $engine,
        private readonly MaterialOptionService $materialOptions,
    ) {}

    /**
     * @return array{
     *     report: array<string, mixed>,
     *     materials: array<string, TradeMaterial>,
     *     quotes: array<string, list<array<string, mixed>>>
     * }
     */
    public function run(FormulaVersion $version): array
    {
        $version->loadMissing('lines.tradeMaterial.substance');
        $materials = $version->lines->mapWithKeys(fn (FormulaLine $line): array => [$line->trade_material_id => $line->tradeMaterial])->all();
        $options = $this->materialOptions->forMaterials(collect($materials));

        $quotes = [];
        $candidates = [];
        $fxRates = [];
        foreach ($options as $materialId => $option) {
            $quotes[$materialId] = $option['prices'];
            $candidates[$materialId] = array_map(fn (array $price): array => [
                'value' => $price['amount'],
                'unit' => $price['currency'],
                'sourceId' => $price['source_id'],
                'confidence' => $price['confidence'],
                'validFrom' => $price['quoted_on'],
            ], $option['prices']);

            foreach ($option['prices'] as $price) {
                if ($price['fx'] !== null) {
                    // A rate chosen for one quote day applies to that day's price only (engine FxRate.forDate).
                    $fxRates[] = [
                        'from' => $price['currency'],
                        'to' => 'EGP',
                        'rate' => $price['fx']['rate'],
                        'date' => $price['fx']['rate_date'],
                        'sourceId' => $price['fx']['source_id'],
                        'source' => $price['fx']['source_title'],
                        'confidence' => $price['fx']['confidence'],
                        'forDate' => $price['quoted_on'],
                    ];
                }
            }
        }

        $report = $this->engine->run('cost-report', [
            'datasetVersion' => DatasetVersion::query()->latest('created_at')->value('id') ?? config('formula.unpublished_dataset_version'),
            'lines' => $version->lines->map(fn (FormulaLine $line): array => array_filter([
                'materialId' => $line->trade_material_id,
                'percentWW' => $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww),
                'qs' => $line->is_qs ?: null,
            ], fn (mixed $part): bool => $part !== null))->values()->all(),
            'quotes' => (object) $candidates,
            'fxRates' => $fxRates,
        ]);

        return ['report' => $report, 'materials' => $materials, 'quotes' => $quotes];
    }
}
