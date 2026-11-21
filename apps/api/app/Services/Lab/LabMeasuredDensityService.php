<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;

/**
 * The density measured in the lab for a formula version (O-04): the newest density reading of any of its trials. It is the
 * density of the PRODUCT, so the litre/kilo conversion prefers it to an estimated (material) one; the engine decides that
 * (preferMeasuredDensity) and keeps this reading's source and confidence in the trace. Nothing is stored on the version
 * itself: an approved version is immutable, and the reading already links to it through its trial.
 */
final class LabMeasuredDensityService
{
    public function forVersion(FormulaVersion $version): ?LabMeasurement
    {
        return LabMeasurement::query()
            ->with('trial')
            ->where('property', LabProperty::Density)
            ->whereHas('trial', fn ($trial) => $trial->where('formula_version_id', $version->getKey()))
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The reading as a sourced density for the calculators' field and the engine: { value, unit, sourceId, sourceTitle,
     * confidence, validFrom, href, ... }; null when the version has no density reading.
     *
     * @return array<string, mixed>|null
     */
    public function payload(FormulaVersion $version): ?array
    {
        $reading = $this->forVersion($version);

        if ($reading === null) {
            return null;
        }

        $version->loadMissing('formula');

        return [
            'value' => $reading->value,
            'unit' => $reading->unit,
            'sourceId' => FormulaSaltCurveService::SOURCE_PREFIX.$reading->getKey(),
            'sourceTitle' => __('lab.density.source_title', ['trial' => $reading->trial->code]),
            'confidence' => $reading->confidence->value,
            'validFrom' => $reading->measured_at->toDateString(),
            'href' => route('lab.trials.show', $reading->trial, false),
            'trial' => $reading->trial->code,
            'version' => $version->version,
            'formula' => $version->formula->code,
        ];
    }
}
