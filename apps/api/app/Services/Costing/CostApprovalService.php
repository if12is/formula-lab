<?php

namespace App\Services\Costing;

use App\Actions\Costing\ApproveFormulaCostAction;
use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Exceptions\Domain\CostApprovalException;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * The cost approval of a product (Y-05, G-09): a person confirms the unit cost kept on the formula, on the latest approved formula
 * version, once the engine's cost report of that version is complete. An approval stops counting as soon as the formula has a newer
 * approved version or the unit cost (or its source) was changed: the cost then has to be approved again.
 */
final class CostApprovalService
{
    public function __construct(
        private readonly ApproveFormulaCostAction $approveCost,
        private readonly CostReportEngineService $engineReport,
    ) {}

    /** The newest approved version of the formula, or null when none is approved. */
    public function latestApprovedVersion(Formula $formula): ?FormulaVersion
    {
        return $formula->versions()->reorder()->where('status', FormulaStatus::Approved)->orderByDesc('version')->first();
    }

    /** The newest approval ever recorded, valid or not. */
    public function latest(Formula $formula): ?FormulaCostApproval
    {
        return FormulaCostApproval::query()->where('formula_id', $formula->getKey())->orderByDesc('approved_at')->orderByDesc('id')->first();
    }

    /**
     * The approval that counts now, or null: the newest one, on the latest approved version, for the unit cost kept on the formula.
     */
    public function current(Formula $formula): ?FormulaCostApproval
    {
        return $this->staleReason($formula) === null ? $this->latest($formula) : null;
    }

    /**
     * Why the newest approval no longer counts: `none` (never approved), `version` (a newer approved version exists) or `unit_cost`
     * (the unit cost or its source changed since). Null when the newest approval is in force.
     */
    public function staleReason(Formula $formula): ?string
    {
        $approval = $this->latest($formula);
        if ($approval === null) {
            return 'none';
        }
        if ($approval->formula_version_id !== $this->latestApprovedVersion($formula)?->getKey()) {
            return 'version';
        }
        if ($formula->unit_cost === null
            || DecimalNormalizer::normalize($formula->unit_cost) !== DecimalNormalizer::normalize($approval->unit_cost)
            || $formula->unit_cost_source_id !== $approval->unit_cost_source_id
            || $formula->unit_cost_confidence !== $approval->unit_cost_confidence) {
            return 'unit_cost';
        }

        return null;
    }

    /**
     * @throws CostApprovalException When the latest version is not approved, the unit cost is missing or only an estimate, or the engine's cost report has gaps.
     */
    public function approve(Formula $formula, ?User $user, ?string $comment): FormulaCostApproval
    {
        $version = $this->latestApprovedVersion($formula);
        if ($version === null) {
            throw CostApprovalException::versionNotApproved($formula->code);
        }
        $formula->loadMissing('unitCostSource');
        if ($formula->unit_cost === null || $formula->unit_cost_source_id === null || $formula->unit_cost_confidence === null) {
            throw CostApprovalException::unitCostMissing($formula->code);
        }
        // An estimate is not something to approve a cost on: the engine refuses to cost with one without a signed override.
        if ($formula->unit_cost_confidence === Confidence::L7Estimate) {
            throw CostApprovalException::unitCostIsEstimate($formula->code);
        }

        $run = $this->engineReport->run($version);
        $report = $run['report'];
        if (! $report['ok']) {
            throw CostApprovalException::reportIncomplete($this->describeGaps($report['missing'], $run['materials']));
        }

        $prices = [];
        foreach ($report['lines'] as $line) {
            $quote = $run['quotes'][$line['materialId']][$line['quoteIndex']];
            $prices[$line['materialId']] = ['egp_per_kg' => $line['priceEgpPerKg'], 'quote_id' => $quote['id'], 'confidence' => $quote['confidence']];
        }

        return DB::transaction(fn (): FormulaCostApproval => ($this->approveCost)($formula, $version, $prices, $report['inputHash'], $report['outputHash'], $user?->getKey(), $comment));
    }

    /**
     * @param  list<array<string, mixed>>  $missing
     * @param  array<string, TradeMaterial>  $materials
     * @return list<string>
     */
    private function describeGaps(array $missing, array $materials): array
    {
        return array_map(function (array $gap) use ($materials): string {
            $name = isset($gap['materialId']) ? ($materials[$gap['materialId']]->trade_name ?? $gap['materialId']) : __('cost_report.product');
            $reason = (string) $gap['reason'];

            return $name.': '.(__('engine.'.$reason) === 'engine.'.$reason ? $reason : __('engine.'.$reason));
        }, $missing);
    }
}
