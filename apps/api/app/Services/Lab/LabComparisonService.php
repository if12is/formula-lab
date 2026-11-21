<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaLine;
use App\Models\LabTrial;
use App\Services\Material\MaterialDetailService;

/**
 * What the expected-vs-measured view of a trial needs (O-05): the lines of the trial's version with the registered active
 * matter of each material, and the measured active matter of the trial. The expected value and the difference are the
 * engine's (activeMatterOfBlend, compareExpectedMeasured) and are worked out in the browser; this only hands over inputs.
 */
final class LabComparisonService
{
    public function __construct(private MaterialDetailService $materialDetail) {}

    /**
     * @return array{property: string, lines: list<array<string, mixed>>, measured: array<string, mixed>|null}
     */
    public function forTrial(LabTrial $trial): array
    {
        $property = (string) config('lab.comparison.material_property');
        $trial->loadMissing('formulaVersion.lines.tradeMaterial');

        return [
            'property' => $property,
            'lines' => $trial->formulaVersion->lines->map(fn (FormulaLine $line): array => [
                'key' => $line->getKey(),
                'material_id' => $line->trade_material_id,
                'label' => $line->tradeMaterial->trade_name,
                'percent' => $line->percent_ww,
                'is_qs' => $line->is_qs,
                'properties' => array_values(array_filter(
                    $this->materialDetail->properties($line->tradeMaterial),
                    fn (array $row): bool => $row['property'] === $property,
                )),
            ])->all(),
            'measured' => $this->measured($trial),
        ];
    }

    /** @return array<string, mixed>|null The latest reading of the compared property, as a sourced value. */
    private function measured(LabTrial $trial): ?array
    {
        $reading = $trial->measurements()->where('property', LabProperty::from((string) config('lab.comparison.lab_property')))->get()->last();

        if ($reading === null) {
            return null;
        }

        return [
            'value' => $reading->value,
            'unit' => $reading->unit,
            'sourceId' => FormulaSaltCurveService::SOURCE_PREFIX.$reading->getKey(),
            'sourceTitle' => __('lab.density.source_title', ['trial' => $trial->code]),
            'confidence' => $reading->confidence->value,
            'validFrom' => $reading->measured_at->toDateString(),
            'method' => $reading->protocol === null ? $reading->method : __("lab.protocols.{$reading->protocol->code}.name"),
        ];
    }
}
