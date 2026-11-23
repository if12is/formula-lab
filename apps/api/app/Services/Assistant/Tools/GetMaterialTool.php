<?php

namespace App\Services\Assistant\Tools;

use App\Models\TradeMaterial;
use App\Services\Material\MaterialDetailService;
use App\Services\Material\MaterialOptionService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;

/** A trade material with its sourced property values and dated price quotes, read from the materials services (nothing is guessed). */
final class GetMaterialTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(
        private readonly MaterialOptionService $options,
        private readonly MaterialDetailService $details,
    ) {}

    public function name(): string
    {
        return 'get_material';
    }

    public function description(): string
    {
        return 'Fetch trade materials by name, INCI or CAS (query) or by id (materialId) with their sourced properties and dated price quotes. Each value carries its source and confidence level (L1 best to L7 estimate). A material with no price is reported in missing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Part of the trade name, grade, INCI or CAS.'],
                'materialId' => ['type' => 'string', 'description' => 'The id of one trade material.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['query' => ['nullable', 'string', 'max:200'], 'materialId' => ['nullable', 'uuid']]);
        $query = trim((string) ($valid['query'] ?? ''));
        $id = $valid['materialId'] ?? null;

        if ($id === null && $query === '') {
            return $this->missing([['property' => 'query_or_materialId', 'reason' => 'input.missing']]);
        }

        $materials = $id !== null
            ? TradeMaterial::query()->with('substance')->whereKey($id)->get()
            : TradeMaterial::query()->with('substance')
                ->whereIn('id', array_column($this->options->search($query), 'value'))
                ->orderBy('trade_name')
                ->get()
                ->take((int) config('assistant.material_limit'));

        if ($materials->isEmpty()) {
            return $this->missing([['property' => 'material', 'reason' => 'material.not_found']], ['count' => 0, 'materials' => []]);
        }

        $missing = [];
        $rows = [];
        foreach ($materials as $material) {
            $prices = $this->details->prices($material);
            if ($prices === []) {
                $missing[] = ['materialId' => $material->getKey(), 'property' => 'price', 'reason' => 'price.no_quote'];
            }
            $rows[] = [
                'id' => $material->getKey(),
                'name' => $material->trade_name,
                'grade' => $material->grade,
                'function' => $material->function,
                'inci' => $material->substance?->inci,
                'cas' => $material->substance?->cas,
                'properties' => array_map(fn (array $value): array => [
                    'property' => $value['property'],
                    'value' => $value['value'],
                    'min' => $value['min'],
                    'max' => $value['max'],
                    'unit' => $value['unit'],
                    'source' => $value['source_title'],
                    'sourceId' => $value['source_id'],
                    'confidence' => $value['confidence'],
                    'validFrom' => $value['valid_from'],
                ], $this->details->properties($material)),
                'prices' => array_map(fn (array $price): array => [
                    'amount' => $price['amount'],
                    'currency' => $price['currency'],
                    'perUnit' => $price['per_unit'],
                    'quotedOn' => $price['quoted_on'],
                    'source' => $price['source_title'],
                    'sourceId' => $price['source_id'],
                    'confidence' => $price['confidence'],
                ], $prices),
            ];
        }

        return $missing === []
            ? $this->answer(['count' => count($rows), 'materials' => $rows])
            : $this->missing($missing, ['count' => count($rows), 'materials' => $rows]);
    }
}
