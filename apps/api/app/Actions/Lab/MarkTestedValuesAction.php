<?php

namespace App\Actions\Lab;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\LabProperty;
use App\Enums\TestedValueKind;
use App\Exceptions\Domain\LabTrialException;
use App\Models\FormulaLine;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\TestedValueMark;
use App\Services\Material\MaterialDetailService;
use App\Support\Engine\EngineRunner;
use Illuminate\Support\Facades\DB;

/**
 * Records "tested on formula X" for one trial (O-07): when the trial is completed, holds a stability result and its version is
 * approved, the doses and the required HLBs the engine says the version used (script `tested-values`) become provenance
 * records (L1, local lab history) pointing at that stability reading. No threshold is applied: a stability result that stands
 * on an approved version is the owner's own judgement. Idempotent: one record per trial, material and kind, so running it again
 * (the trial completed first, the approval came later, or the other way round) adds nothing twice.
 */
final class MarkTestedValuesAction
{
    public function __construct(
        private EngineRunner $engine,
        private MaterialDetailService $materialDetail,
    ) {}

    /**
     * @return int How many records were added (0 when the trial does not qualify yet or everything was already recorded).
     *
     * @throws LabTrialException When the engine cannot work out the version's values.
     */
    public function __invoke(LabTrial $trial): int
    {
        $trial->loadMissing('formulaVersion.formula', 'formulaVersion.lines.tradeMaterial');
        $version = $trial->formulaVersion;

        if (! $trial->isCompleted() || $version->status !== FormulaStatus::Approved) {
            return 0;
        }

        $stability = $trial->measurements()->where('property', LabProperty::from((string) config('lab.stability_property')))->get()->last();

        if ($stability === null) {
            return 0;
        }

        $answer = $this->engine->run('tested-values', $this->engineInput($version->lines->all()));

        if (($answer['ok'] ?? false) !== true) {
            throw LabTrialException::testedValuesNotComputed($trial->code);
        }

        return DB::transaction(function () use ($trial, $stability, $answer): int {
            $added = 0;

            foreach ($answer['values'] as $value) {
                $mark = TestedValueMark::query()->firstOrCreate(
                    ['lab_trial_id' => $trial->getKey(), 'trade_material_id' => $value['materialId'], 'kind' => TestedValueKind::from($value['kind'])],
                    $this->attributes($trial, $stability, $value),
                );
                $added += $mark->wasRecentlyCreated ? 1 : 0;
            }

            return $added;
        });
    }

    /**
     * @param  list<FormulaLine>  $lines
     * @return array{lines: list<array<string, mixed>>, rhlb: array<string, list<array<string, string>>>}
     */
    private function engineInput(array $lines): array
    {
        $rhlb = [];

        foreach ($lines as $line) {
            $candidates = array_values(array_filter(
                $this->materialDetail->properties($line->tradeMaterial),
                fn (array $row): bool => $row['property'] === TestedValueKind::RequiredHlb->value && $row['value'] !== null,
            ));

            if ($candidates !== []) {
                $rhlb[$line->trade_material_id] = array_map(fn (array $row): array => array_filter([
                    'value' => $row['value'],
                    'sourceId' => $row['source_id'],
                    'confidence' => $row['confidence'],
                    'validFrom' => $row['valid_from'],
                    'lotNo' => $row['lot_no'],
                ], fn (mixed $part): bool => $part !== null), $candidates);
            }
        }

        return [
            'lines' => array_map(fn (FormulaLine $line): array => array_filter([
                'materialId' => $line->trade_material_id,
                'percentWW' => $line->percent_ww,
                'qs' => $line->is_qs ? true : null,
            ], fn (mixed $part): bool => $part !== null), $lines),
            'rhlb' => (object) $rhlb,
        ];
    }

    /**
     * @param  array<string, string>  $value
     * @return array<string, mixed>
     */
    private function attributes(LabTrial $trial, LabMeasurement $stability, array $value): array
    {
        return [
            'tenant_id' => $trial->tenant_id,
            'formula_version_id' => $trial->formula_version_id,
            'lab_measurement_id' => $stability->getKey(),
            'value' => $value['value'],
            'source_id' => $value['sourceId'] ?? null,
            'confidence' => Confidence::L1CoaBatch,
            'tested_on' => $stability->measured_at->toDateString(),
        ];
    }
}
