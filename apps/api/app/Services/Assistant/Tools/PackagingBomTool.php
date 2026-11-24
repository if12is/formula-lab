<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\PackagingBom;
use App\Services\Packaging\PackagingBomCostService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;

/** The packaging cost per selling unit of one SKU's packaging BOM, by the engine's packaging-bom-cost script (the BOM editor's preview). */
final class PackagingBomTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(private readonly PackagingBomCostService $cost) {}

    public function name(): string
    {
        return 'packaging_bom';
    }

    public function description(): string
    {
        return 'Deterministic engine: the packaging cost per selling unit (EGP) of a SKU packaging BOM (packagingBomId): the total and each component per unit, with reject share, carton and pallet share and the declared neck/closure pairs. A figure the BOM does not have (price, quantity, reject %, packing, a declared pair) is reported in missing and no total is given.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'packagingBomId' => ['type' => 'string', 'description' => 'The id of the packaging BOM of one SKU.'],
            ],
            'required' => ['packagingBomId'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaCost];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['packagingBomId' => ['required', 'uuid']]);
        $bom = PackagingBom::query()->find($valid['packagingBomId']);
        if ($bom === null) {
            return $this->missing([['property' => 'packagingBom', 'reason' => 'packaging_bom.not_found']]);
        }

        $preview = $this->cost->preview($bom);
        $data = ['sku' => $bom->sku_code, 'name' => $bom->name];

        if ($preview['lines'] === [] && $preview['missing'] === [] && ! $preview['ok']) {
            return $this->missing([['property' => 'lines', 'reason' => 'packaging_bom.no_lines']], $data);
        }
        if (! $preview['ok']) {
            return $this->missing($this->plainGaps($preview['missing']), $data, $preview['warnings']);
        }

        return $this->answer([
            ...$data,
            'total' => $preview['total'],
            'lines' => array_map(fn (array $line): array => ['name' => $line['name'], 'perUnit' => $line['per_unit']], $preview['lines']),
        ], $preview['warnings']);
    }
}
