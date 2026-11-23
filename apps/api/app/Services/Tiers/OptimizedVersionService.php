<?php

namespace App\Services\Tiers;

use App\Actions\Tiers\RecordOptimizerProvenanceAction;
use App\Enums\ProductTier;
use App\Exceptions\Domain\OptimizedVersionException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\User;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Support\Facades\DB;

/**
 * J-09: an optimized answer becomes a new version of the product, always a draft. The optimizer is run again on the saved profile (the
 * screen's numbers are never trusted back from the browser), so the lines are exactly what the optimizer gives for those inputs, and the
 * inputs, the tier profile, the solver version and the input hash are written with the version. Nothing here moves the version on: approval is
 * the lab workflow's (O-02) and needs a completed trial.
 */
final class OptimizedVersionService
{
    public function __construct(
        private readonly TierSolveService $solver,
        private readonly FormulaVersionService $versions,
        private readonly RecordOptimizerProvenanceAction $recordProvenance,
    ) {}

    /**
     * @param  array{qs_material_id?: string|null}  $input
     *
     * @throws OptimizedVersionException When the optimizer has no optimal formula for the profile.
     */
    public function saveDraft(Formula $formula, ProductTier $tier, array $input, ?User $user = null): FormulaVersion
    {
        $run = $this->solver->run($formula, $tier, ['qs_material_id' => $input['qs_material_id'] ?? null]);
        $answer = $run['answer'];

        if (($answer['status'] ?? null) !== 'optimal' || ($answer['formula'] ?? []) === []) {
            throw OptimizedVersionException::notOptimal((string) ($answer['status'] ?? 'error'));
        }

        $lines = array_map(fn (array $line): array => [
            'trade_material_id' => $line['materialId'],
            'percent_ww' => $line['percentWW'],
            'is_qs' => false,
        ], $answer['formula']);

        return DB::transaction(function () use ($formula, $tier, $lines, $user, $run, $answer): FormulaVersion {
            $version = $this->versions->createVersion($formula, $lines, __('tiers.save_version.reason', ['tier' => $tier->label()]), $user);
            ($this->recordProvenance)($version, $tier, $run['record'], $run['request'], $answer);

            return $version;
        });
    }
}
