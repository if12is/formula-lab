<?php

namespace App\Services\Lab;

use App\Actions\Lab\MarkTestedValuesAction;
use App\Enums\LabTrialStatus;
use App\Exceptions\Domain\LabTrialException;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use App\Models\TestedValueMark;
use App\Support\DecimalNormalizer;

/**
 * "Tested on formula X" (O-07): the single write path of tested_value_marks, and the read side the screens use. A record is
 * provenance (local lab history, L1): reading it never changes a value, and the engine only tags a value that equals the
 * recorded one (testedOnTags).
 */
final class TestedValueService
{
    public function __construct(private MarkTestedValuesAction $mark) {}

    /**
     * Records the tested values of one trial, when it qualifies (completed, with a stability result, on an approved version).
     *
     * @return int How many records were added.
     *
     * @throws LabTrialException
     */
    public function markTrial(LabTrial $trial): int
    {
        return ($this->mark)($trial);
    }

    /**
     * Records the tested values of every completed trial of a version, for when the version is approved after its trials.
     *
     * @return int How many records were added.
     *
     * @throws LabTrialException
     */
    public function markVersion(FormulaVersion $version): int
    {
        return $version->trials()->where('status', LabTrialStatus::Completed)->get()->sum(fn (LabTrial $trial): int => $this->markTrial($trial));
    }

    /**
     * The records of some materials in the engine's TestedMark shape, for the calculators' tags.
     *
     * @param  list<string>  $materialIds
     * @return array<string, list<array<string, mixed>>> Keyed by material id.
     */
    public function forMaterials(array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        $marks = TestedValueMark::query()
            ->with(['formulaVersion.formula', 'trial'])
            ->whereIn('trade_material_id', $materialIds)
            ->orderByDesc('tested_on')
            ->orderBy('id')
            ->get();

        return $marks->groupBy('trade_material_id')->map(fn ($group): array => $group->map(fn (TestedValueMark $mark): array => $this->enginePayload($mark))->values()->all())->all();
    }

    /**
     * The records of one version for its page: what was tested, at which value, on which trial.
     *
     * @return list<array<string, mixed>>
     */
    public function forVersion(FormulaVersion $version): array
    {
        return TestedValueMark::query()
            ->with(['material', 'trial'])
            ->where('formula_version_id', $version->getKey())
            ->get()
            ->sortBy(fn (TestedValueMark $mark): string => $mark->kind->value.'|'.$mark->material->trade_name)
            ->map(fn (TestedValueMark $mark): array => [
                'id' => $mark->getKey(),
                'kind' => $mark->kind->value,
                'material' => $mark->material->trade_name,
                'material_id' => $mark->trade_material_id,
                'value' => DecimalNormalizer::normalize($mark->value),
                'confidence' => $mark->confidence->value,
                'tested_on' => $mark->tested_on->toDateString(),
                'trial' => $mark->trial->code,
                'trial_href' => route('lab.trials.show', $mark->trial, false),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function enginePayload(TestedValueMark $mark): array
    {
        $version = $mark->formulaVersion;

        return [
            'kind' => $mark->kind->value,
            'materialId' => $mark->trade_material_id,
            'value' => DecimalNormalizer::normalize($mark->value),
            'formulaId' => $version->formula_id,
            'formulaCode' => $version->formula->code,
            'version' => $version->version,
            'trialId' => $mark->lab_trial_id,
            'trialCode' => $mark->trial->code,
            'testedOn' => $mark->tested_on->toDateString(),
            'confidence' => $mark->confidence->value,
        ];
    }
}
