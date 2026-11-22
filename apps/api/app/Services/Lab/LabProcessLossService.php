<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;

/**
 * The process losses measured in the lab for a formula version (L-04): per stage (ageing, chilling, filtration) the newest
 * reading of any of its trials, as a sourced value for the perfume cost calculator. A stage no trial measured is null: the
 * engine (processLoss) then reports it missing, it is never read as zero.
 */
final class LabProcessLossService
{
    /**
     * @return array<string, array<string, mixed>|null> Keyed by the engine's stage name.
     */
    public function payload(FormulaVersion $version): array
    {
        $version->loadMissing('formula');

        return collect((array) config('lab.process_loss'))
            ->map(function (string $property) use ($version): ?array {
                $reading = $this->latest($version, LabProperty::from($property));

                return $reading === null ? null : [
                    'value' => $reading->value,
                    'unit' => $reading->unit,
                    'sourceId' => FormulaSaltCurveService::SOURCE_PREFIX.$reading->getKey(),
                    'sourceTitle' => __('lab.process_loss.source_title', ['trial' => $reading->trial->code]),
                    'confidence' => $reading->confidence->value,
                    'validFrom' => $reading->measured_at->toDateString(),
                    'href' => route('lab.trials.show', $reading->trial, false),
                    'trial' => $reading->trial->code,
                    'version' => $version->version,
                    'formula' => $version->formula->code,
                ];
            })
            ->all();
    }

    private function latest(FormulaVersion $version, LabProperty $property): ?LabMeasurement
    {
        return LabMeasurement::query()
            ->with('trial')
            ->where('property', $property)
            ->whereHas('trial', fn ($trial) => $trial->where('formula_version_id', $version->getKey()))
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();
    }
}
