<?php

namespace App\Services\Production;

use App\Enums\Production\BatchLineKind;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Support\DecimalNormalizer;
use App\Support\Engine\ProductionEngine;
use Illuminate\Support\Collection;

/**
 * Q-03: the alkali (lye for a soap, base for a neutralisation) recomputed with the values on the COA of the lots really weighed, by the
 * engine's own soap and neutralisation functions, against the same masses at the reference values the formula was designed with.
 * The base line is the one whose substance has a CAS in config production.alkali_cas; oils are the lines that have a SAP on file and an
 * acid the line that has an acid value. A lot with no COA value stops the calculation and is named: the reference value never stands in.
 * Superfat is a formulation choice, typed by the person (never stored or assumed).
 */
final class BatchAlkaliService
{
    public function __construct(
        private readonly ProductionEngine $engine,
        private readonly ReferenceValueFinder $values,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forBatch(ProductionBatch $batch, ?string $superfatPct): array
    {
        $batch->loadMissing('lines.tradeMaterial.substance');
        $raw = $batch->lines->where('kind', BatchLineKind::RawMaterial);

        $base = null;
        foreach ($raw as $line) {
            $name = config('production.alkali_cas')[$line->tradeMaterial->substance?->cas ?? ''] ?? null;
            if ($name !== null) {
                $base = ['line' => $line, 'name' => $name];
                break;
            }
        }

        $oils = $raw->filter(fn (ProductionBatchLine $line): bool => $this->values->planned($line->tradeMaterial, 'sap') !== null)->values();
        $acids = $raw->filter(fn (ProductionBatchLine $line): bool => $this->values->planned($line->tradeMaterial, 'acid_value') !== null)->values();

        $none = ['applicable' => false, 'kind' => null, 'result' => null, 'missing' => [], 'needs' => null, 'lots' => $this->lots($raw)];
        if ($base === null || ($oils->isEmpty() && $acids->isEmpty())) {
            return $none;
        }

        $kind = $oils->isNotEmpty() ? 'soap' : 'neutralize';
        if ($kind === 'soap' && ($superfatPct === null || $superfatPct === '')) {
            return [...$none, 'applicable' => true, 'kind' => $kind, 'needs' => 'superfat', 'base' => $base['name']];
        }

        $baseLine = $base['line'];
        $baseLot = $baseLine->lot_number === null ? null : $this->values->forLot($baseLine->tradeMaterial, 'purity', $baseLine->lot_number);
        $basePlanned = $this->values->planned($baseLine->tradeMaterial, 'purity');
        if ($basePlanned === null) {
            return [...$none, 'applicable' => true, 'kind' => $kind, 'base' => $base['name'], 'missing' => [__('production.alkali.base_purity_missing', ['material' => $baseLine->tradeMaterial->trade_name])]];
        }

        if ($kind === 'soap') {
            $input = $this->soap($oils, $base['name'], $superfatPct, $basePlanned, $baseLot);
        } else {
            $input = $this->neutralize($acids->first(), $base['name'], $basePlanned, $baseLot);
        }
        if (isset($input['missing'])) {
            return [...$none, 'applicable' => true, 'kind' => $kind, 'base' => $base['name'], 'missing' => $input['missing']];
        }

        $answer = $this->engine->lotAlkali([...$input['input'], 'thresholdPct' => config('production.deviation_threshold_pct')]);
        if (! $answer['ok']) {
            return [...$none, 'applicable' => true, 'kind' => $kind, 'base' => $base['name'], 'missing' => array_map(fn (array $gap): string => $this->gap($gap, $batch), $answer['missing'] ?? [])];
        }

        return [...$none, 'applicable' => true, 'kind' => $kind, 'base' => $base['name'], 'result' => $answer['value'], 'superfat_pct' => $kind === 'soap' ? $superfatPct : null];
    }

    /**
     * @param  Collection<int, ProductionBatchLine>  $oils
     * @param  array<string, string>  $purityPlanned
     * @param  array<string, string>|null  $purityLot
     * @return array{input?: array<string, mixed>, missing?: list<string>}
     */
    private function soap($oils, string $base, string $superfatPct, array $purityPlanned, ?array $purityLot): array
    {
        $rows = [];
        foreach ($oils as $line) {
            $planned = $this->values->planned($line->tradeMaterial, 'sap');
            $lot = $line->lot_number === null ? null : $this->values->forLot($line->tradeMaterial, 'sap', $line->lot_number);
            // The lot value counts only in the unit the planned one is in: a different unit is a value this calculation cannot use.
            if ($lot !== null && $lot['unit'] !== $planned['unit']) {
                $lot = null;
            }
            $rows[] = [
                'oilId' => $line->tradeMaterial->trade_name,
                'massG' => $line->actual_quantity ?? $line->weigh_quantity,
                'sapUnit' => $planned['unit'],
                'planned' => $this->scalar($planned),
                'lot' => $lot === null ? null : $this->scalar($lot),
            ];
        }

        return ['input' => ['kind' => 'soap', 'base' => $base, 'oils' => $rows, 'superfatPct' => $superfatPct, 'purityPlanned' => $this->scalar($purityPlanned), 'purityLot' => $purityLot === null ? null : $this->scalar($purityLot)]];
    }

    /**
     * @param  array<string, string>  $strengthPlanned
     * @param  array<string, string>|null  $strengthLot
     * @return array{input?: array<string, mixed>, missing?: list<string>}
     */
    private function neutralize(ProductionBatchLine $acid, string $base, array $strengthPlanned, ?array $strengthLot): array
    {
        $planned = $this->values->planned($acid->tradeMaterial, 'acid_value');
        $lot = $acid->lot_number === null ? null : $this->values->forLot($acid->tradeMaterial, 'acid_value', $acid->lot_number);

        return ['input' => [
            'kind' => 'neutralize',
            'base' => $base,
            'form' => 'solid',
            'acidId' => $acid->tradeMaterial->trade_name,
            'acidMassG' => $acid->actual_quantity ?? $acid->weigh_quantity,
            'acidValuePlanned' => $this->scalar($planned),
            'acidValueLot' => $lot === null ? null : $this->scalar($lot),
            'strengthPlanned' => $this->scalar($strengthPlanned),
            'strengthLot' => $strengthLot === null ? null : $this->scalar($strengthLot),
        ]];
    }

    /**
     * @param  array<string, string>  $value
     * @return array<string, string>
     */
    private function scalar(array $value): array
    {
        return ['value' => DecimalNormalizer::normalize($value['value']), 'sourceId' => $value['sourceId'], 'confidence' => $value['confidence'], 'validFrom' => $value['validFrom']];
    }

    /**
     * Which COA values each lot of the batch has on file (the link between a weighed lot and its certificate).
     *
     * @param  Collection<int, ProductionBatchLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function lots($lines): array
    {
        return $lines->filter(fn (ProductionBatchLine $line): bool => $line->lot_number !== null)->map(fn (ProductionBatchLine $line): array => [
            'line_no' => $line->line_no,
            'material' => $line->tradeMaterial->trade_name,
            'lot' => $line->lot_number,
            'properties' => $this->values->lotProperties($line->tradeMaterial, $line->lot_number),
        ])->values()->all();
    }

    /** @param  array<string, mixed>  $gap */
    private function gap(array $gap, ProductionBatch $batch): string
    {
        $key = 'engine.'.$gap['reason'];
        $reason = __($key) === $key ? (string) $gap['reason'] : __($key);
        $subject = trim(($gap['materialId'] ?? '').' '.__('production.alkali.properties.'.str_replace('.', '_', (string) $gap['property'])));

        return "{$subject}: {$reason}";
    }
}
