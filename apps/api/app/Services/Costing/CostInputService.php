<?php

namespace App\Services\Costing;

use App\Models\DatasetVersion;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialOptionService;
use App\Support\DecimalNormalizer;
use App\Support\Engine\PriceQuotes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The engine's cost-report input of formula versions (lines, each material's price quotes with the dated rate of the quote day), for
 * the screens that hand many versions to the engine in one call (the price ladder's USD exposure, the dashboard). The quotes of all the
 * versions' materials are read together. Nothing is costed or chosen here: that is the engine's `buildCostReport`.
 */
final class CostInputService
{
    public function __construct(private readonly MaterialOptionService $materialOptions) {}

    /**
     * @param  Collection<int, FormulaVersion>  $versions
     * @return array<string, array{input: array<string, mixed>, materials: array<string, TradeMaterial>}> Keyed by version id.
     */
    public function forVersions(Collection $versions): array
    {
        $versions = new EloquentCollection($versions->all());
        $versions->loadMissing('lines.tradeMaterial');
        $materials = $versions->flatMap(fn (FormulaVersion $version) => $version->lines->map(fn (FormulaLine $line) => $line->tradeMaterial))->unique('id')->values();
        $options = $this->materialOptions->forMaterials($materials);
        $dataset = DatasetVersion::query()->latest('created_at')->value('id') ?? config('formula.unpublished_dataset_version');

        $inputs = [];
        foreach ($versions as $version) {
            $candidates = [];
            $fxRates = [];
            foreach ($version->lines as $line) {
                $prices = $options[$line->trade_material_id]['prices'] ?? [];
                $candidates[$line->trade_material_id] = PriceQuotes::candidates($prices);
                $fxRates = [...$fxRates, ...PriceQuotes::fxRates($prices)];
            }
            $inputs[$version->getKey()] = [
                'input' => [
                    'datasetVersion' => $dataset,
                    'lines' => $version->lines->map(fn (FormulaLine $line): array => array_filter([
                        'materialId' => $line->trade_material_id,
                        'percentWW' => $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww),
                        'qs' => $line->is_qs ?: null,
                    ], fn (mixed $part): bool => $part !== null))->values()->all(),
                    'quotes' => (object) $candidates,
                    'fxRates' => $fxRates,
                ],
                'materials' => $version->lines->mapWithKeys(fn (FormulaLine $line): array => [$line->trade_material_id => $line->tradeMaterial])->all(),
            ];
        }

        return $inputs;
    }
}
