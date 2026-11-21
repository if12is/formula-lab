<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Models\Formula;
use App\Models\LabMeasurement;
use App\Support\DecimalCompare;
use App\Support\DecimalNormalizer;

/**
 * The salt (viscosity) curve of a formula (O-03): its points are the viscosity readings that stated an added salt %, taken on
 * any trial of any version of the formula. They are local lab results (L1) and nothing else: no generic curve exists. Two
 * readings at the same salt % are one point, the newer one (a correction replaces the older one, which stays recorded).
 * The curve is read here and interpolated by the engine (formulaViscosityAtSalt); nothing is computed in PHP.
 */
final class FormulaSaltCurveService
{
    /** The id the engine sees for a point, and the key of the page's source map. */
    public const SOURCE_PREFIX = 'lab_measurement:';

    /**
     * @return list<array<string, mixed>> Ascending by salt %.
     */
    public function points(Formula $formula): array
    {
        $readings = LabMeasurement::query()
            ->with('trial.formulaVersion')
            ->where('property', LabProperty::Viscosity)
            ->whereNotNull('salt_pct')
            ->whereHas('trial.formulaVersion', fn ($version) => $version->where('formula_id', $formula->getKey()))
            ->orderBy('measured_at')
            ->orderBy('id')
            ->get();

        $latest = [];

        foreach ($readings as $reading) {
            $latest[DecimalNormalizer::normalize((string) $reading->salt_pct)] = $reading;
        }

        $points = array_map(fn (LabMeasurement $reading): array => [
            'id' => $reading->getKey(),
            'salt_pct' => DecimalNormalizer::normalize((string) $reading->salt_pct),
            'viscosity' => DecimalNormalizer::normalize((string) $reading->value),
            'unit' => $reading->unit,
            'source_id' => self::SOURCE_PREFIX.$reading->getKey(),
            'confidence' => $reading->confidence->value,
            'measured_at' => $reading->measured_at?->toIso8601String(),
            'trial' => $reading->trial->code,
            'trial_href' => route('lab.trials.show', $reading->trial, false),
            'version' => $reading->trial->formulaVersion->version,
            'instrument' => $reading->instrument,
        ], array_values($latest));

        usort($points, fn (array $a, array $b): int => DecimalCompare::compare($a['salt_pct'], $b['salt_pct']));

        return $points;
    }

    /**
     * The curve of a formula as the engine takes it: { formulaId, points: [{ saltPct, viscosity_mPas, sourceId, confidence }] }.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{formulaId: string, points: list<array<string, string>>}
     */
    public function enginePayload(Formula $formula, array $points): array
    {
        return [
            'formulaId' => $formula->getKey(),
            'points' => array_map(fn (array $point): array => [
                'saltPct' => $point['salt_pct'],
                'viscosity_mPas' => $point['viscosity'],
                'sourceId' => $point['source_id'],
                'confidence' => $point['confidence'],
            ], $points),
        ];
    }

    /**
     * The source map of the explain panel: engine source id -> the trial it was measured in.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, array{title: string, href: string}>
     */
    public function sources(array $points): array
    {
        $sources = [];

        foreach ($points as $point) {
            $sources[$point['source_id']] = [
                'title' => __('lab.salt_curve.source_title', ['trial' => $point['trial'], 'version' => $point['version']]),
                'href' => $point['trial_href'],
            ];
        }

        return $sources;
    }
}
