<?php

namespace App\Services\Formula;

use App\Actions\Formula\TransitionFormulaVersionAction;
use App\Enums\FormulaStatus;
use App\Enums\LabTrialStatus;
use App\Exceptions\Domain\FormulaWorkflowException;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionTransition;
use App\Models\LabTrial;
use App\Models\User;
use App\Services\Lab\LabCoverageService;
use App\Services\Lab\TestedValueService;
use App\Support\DTOs\LabCoverage;
use Illuminate\Support\Facades\DB;

/**
 * The approval workflow of a formula version (O-02): draft -> lab_testing -> approved | rejected, each move signed.
 * Approval needs a completed trial holding every required measurement (config/lab.php); rejection needs a reason.
 */
final class FormulaWorkflowService
{
    /** The approved decision rule whose lab condition the approval gate applies (packages/engine/data/decisions/approve-formula-version.json). */
    private const DECISION_RULE = 'approve-formula-version:AFV-06';

    public function __construct(
        private TransitionFormulaVersionAction $transition,
        private LabCoverageService $coverage,
        private TestedValueService $testedValues,
    ) {}

    /** @throws FormulaWorkflowException */
    public function sendToLab(FormulaVersion $version, ?User $user = null, ?string $comment = null): FormulaVersionTransition
    {
        return ($this->transition)($version, FormulaStatus::LabTesting, $user, $comment);
    }

    /** @throws FormulaWorkflowException When no completed trial holds every required measurement, or the move is not allowed. */
    public function approve(FormulaVersion $version, ?User $user = null, ?string $comment = null): FormulaVersionTransition
    {
        return DB::transaction(function () use ($version, $user, $comment): FormulaVersionTransition {
            $coverage = $this->coverage->forVersion($version);

            if ($coverage->completedTrials === 0) {
                throw FormulaWorkflowException::noCompletedTrial();
            }

            if ($coverage->missing !== []) {
                throw FormulaWorkflowException::measurementsMissing(
                    $coverage->missing,
                    implode(__('lab.list_separator'), $this->coverage->labels($coverage->missing)),
                );
            }

            $signature = ($this->transition)($version, FormulaStatus::Approved, $user, $comment, [
                'decision_rule' => self::DECISION_RULE,
                'lab' => $coverage->toArray(),
            ]);

            // Stability results of its completed trials now stand on an approved version: record what they tested (O-07).
            $this->testedValues->markVersion($version->fresh());

            return $signature;
        });
    }

    /** @throws FormulaWorkflowException When the reason is empty or the move is not allowed. */
    public function reject(FormulaVersion $version, string $reason, ?User $user = null): FormulaVersionTransition
    {
        return ($this->transition)($version, FormulaStatus::Rejected, $user, $reason);
    }

    /**
     * The approval conditions this screen can judge today, in the shape of the shared decision card (Y-08). Only the lab condition
     * (AFV-06) is wired to the server; the other approve-formula-version rules are not yet (see HUMAN-TODO).
     *
     * @return array{state: string, reasons: list<array{rule: string, message: string, remedy: string}>}
     */
    private function decision(LabCoverage $coverage): array
    {
        $reasons = [];
        if ($coverage->missing !== []) {
            $reasons[] = [
                'rule' => 'AFV-06',
                'message' => __('decisions.afv.lab_results_missing', ['missingProperties' => implode(__('lab.list_separator'), $this->coverage->labels($coverage->missing))]),
                'remedy' => __('decisions.remedy.record_lab_results'),
            ];
        } elseif ($coverage->completedTrials === 0) {
            $reasons[] = ['rule' => 'AFV-06', 'message' => __('decisions.afv.no_completed_trial'), 'remedy' => __('decisions.remedy.record_lab_results')];
        }

        return ['state' => $reasons === [] ? 'allowed' : 'blocked', 'reasons' => $reasons];
    }

    /**
     * What the workflow panel of the editor shows for one version: status, allowed moves, signatures, lab coverage and trials.
     *
     * @return array<string, mixed>
     */
    public function panel(FormulaVersion $version): array
    {
        $coverage = $this->coverage->forVersion($version);
        $version->loadMissing('transitions.signer');

        return [
            'status' => $version->status->value,
            'next' => array_map(fn (FormulaStatus $status): string => $status->value, $version->status->allowedNext()),
            'hash' => $version->hash,
            'coverage' => $coverage->toArray(),
            'decision' => $this->decision($coverage),
            'signatures' => $version->transitions->map(fn (FormulaVersionTransition $row): array => [
                'id' => $row->getKey(),
                'from' => $row->from_status->value,
                'to' => $row->to_status->value,
                'by' => $row->signer?->name,
                'at' => $row->signed_at?->toIso8601String(),
                'comment' => $row->comment,
                'hash' => $row->version_hash,
            ])->all(),
            'trials' => $version->trials()->withCount('measurements')->get()->map(fn (LabTrial $trial): array => [
                'id' => $trial->getKey(),
                'code' => $trial->code,
                'status' => $trial->status->value,
                'date' => $trial->trial_date?->toDateString(),
                'completed' => $trial->status === LabTrialStatus::Completed,
                'measurements' => $trial->measurements_count,
                'href' => route('lab.trials.show', $trial, false),
            ])->all(),
            'urls' => [
                'sendToLab' => route('formulas.versions.send-to-lab', [$version->formula_id, $version->version], false),
                'approve' => route('formulas.versions.approve', [$version->formula_id, $version->version], false),
                'reject' => route('formulas.versions.reject', [$version->formula_id, $version->version], false),
                'saltCurve' => route('formulas.salt-curve', $version->formula_id, false),
                'newTrial' => route('lab.trials.create', ['formula_version' => $version->getKey()], false),
            ],
        ];
    }
}
