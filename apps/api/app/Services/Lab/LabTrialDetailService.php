<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabProtocol;
use App\Models\LabTrial;
use Illuminate\Support\Collection;

/** Props of the trial pages: the entry form's choices and the trial with its measurements grouped by property. */
final class LabTrialDetailService
{
    public function __construct(
        private LabCoverageService $coverage,
        private LabLookupService $lookup,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formChoices(?FormulaVersion $version): array
    {
        return [
            'version' => $version === null ? null : $this->lookup->option($version),
            'batchUnits' => array_map(fn (string $unit): array => ['value' => $unit, 'label' => $unit], config('lab.batch_units')),
            'today' => now()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forTrial(LabTrial $trial): array
    {
        $trial->load(['formulaVersion.formula', 'measurements.protocol']);
        $version = $trial->formulaVersion;
        $required = $this->coverage->requiredFor($version->formula);
        $byProperty = $trial->measurements->groupBy(fn (LabMeasurement $measurement): string => $measurement->property->value);

        return [
            'trial' => [
                'id' => $trial->getKey(),
                'code' => $trial->code,
                'status' => $trial->status->value,
                'batch_size' => $trial->batch_size,
                'batch_unit' => $trial->batch_unit,
                'trial_date' => $trial->trial_date?->toDateString(),
                'operator' => $trial->operator,
                'notes' => $trial->notes,
                'completed_at' => $trial->completed_at?->toIso8601String(),
                'open' => ! $trial->isCompleted(),
            ],
            'formula' => [
                'id' => $version->formula->getKey(),
                'code' => $version->formula->code,
                'name' => $version->formula->name,
                'version' => $version->version,
                'status' => $version->status->value,
                'editorHref' => route('formulas.versions.edit', [$version->formula, $version->version], false),
            ],
            'properties' => collect(LabProperty::cases())->map(fn (LabProperty $property): array => $this->propertyRow($property, $required, $byProperty->get($property->value, collect())))->all(),
            'coverage' => $this->coverage->forVersion($version)->toArray(),
            'form' => [
                'propertyOptions' => LabProperty::options(),
                'units' => collect(LabProperty::cases())->mapWithKeys(fn (LabProperty $property): array => [$property->value => array_map(fn (string $unit): array => ['value' => $unit, 'label' => $unit], $property->units())])->all(),
                'protocols' => LabProtocol::query()->orderBy('position')->get()->map(fn (LabProtocol $protocol): array => [
                    'value' => $protocol->getKey(),
                    'code' => $protocol->code,
                    'property' => $protocol->property->value,
                    'unit' => $protocol->unit,
                    'label' => __("lab.protocols.{$protocol->code}.name"),
                    'instrument' => __("lab.instruments.{$protocol->instrument}"),
                ])->all(),
                'now' => now()->format('Y-m-d\TH:i'),
            ],
            'urls' => [
                'measurements' => route('lab.trials.measurements.store', $trial, false),
                'complete' => route('lab.trials.complete', $trial, false),
                'index' => route('lab.trials.index', [], false),
            ],
        ];
    }

    /**
     * One property of the trial: its latest value (the one that counts), the earlier ones it replaced and its acceptance limits.
     *
     * @param  list<string>  $required
     * @param  Collection<int, LabMeasurement>  $measurements  In the order they were recorded.
     * @return array<string, mixed>
     */
    private function propertyRow(LabProperty $property, array $required, Collection $measurements): array
    {
        $latest = $measurements->last();

        return [
            'property' => $property->value,
            'required' => in_array($property->value, $required, true),
            'protocol' => $property->protocolCode(),
            'limits' => $property->limits(),
            'latest' => $latest === null ? null : $this->measurement($latest),
            'earlier' => $measurements->slice(0, max(0, $measurements->count() - 1))->reverse()->values()->map(fn (LabMeasurement $measurement): array => $this->measurement($measurement))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function measurement(LabMeasurement $measurement): array
    {
        return [
            'id' => $measurement->getKey(),
            'value' => $measurement->value,
            'unit' => $measurement->unit,
            'method' => $measurement->method,
            'protocol' => $measurement->protocol === null ? null : __("lab.protocols.{$measurement->protocol->code}.name"),
            'protocol_href' => $measurement->protocol === null ? null : route('lab.protocols.show', $measurement->protocol, false),
            'instrument' => $measurement->instrument,
            'measured_at' => $measurement->measured_at?->toIso8601String(),
            'confidence' => $measurement->confidence->value,
            'note' => $measurement->note,
        ];
    }
}
