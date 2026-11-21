<?php

namespace App\Services\Calculator;

use App\Models\TradeMaterial;
use App\Services\Lab\TestedValueService;
use App\Services\Material\MaterialDetailService;
use App\Services\Material\MaterialOptionService;
use App\Support\PropertyRegistry;

/**
 * Materials as selector options for the calculators: the plain option (name, grade) plus every registered property
 * value the material has, its own and its substance's, so the page can hand them to the engine's resolveValue, and the
 * "tested on formula X" records of the material (O-07), which the engine tags a used dose or required HLB with.
 */
final class CalculatorMaterialService
{
    public function __construct(
        private MaterialOptionService $materialOptionService,
        private MaterialDetailService $materialDetailService,
        private TestedValueService $testedValues,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $term): array
    {
        $registered = PropertyRegistry::keys();
        $options = $this->materialOptionService->search($term);
        $marks = $this->testedValues->forMaterials(array_column($options, 'value'));

        return array_map(function (array $option) use ($registered, $marks): array {
            $material = TradeMaterial::query()->findOrFail($option['value']);
            $properties = array_values(array_filter(
                $this->materialDetailService->properties($material),
                fn (array $row): bool => in_array($row['property'], $registered, true),
            ));

            return [
                'value' => $option['value'],
                'label' => $option['label'],
                'description' => $option['description'],
                'properties' => $properties,
                'testedMarks' => $marks[$option['value']] ?? [],
            ];
        }, $options);
    }
}
