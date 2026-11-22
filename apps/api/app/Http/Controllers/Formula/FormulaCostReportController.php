<?php

namespace App\Http\Controllers\Formula;

use App\Enums\Confidence;
use App\Enums\SourceKind;
use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Costing\ApproveFormulaCostRequest;
use App\Models\Formula;
use App\Services\Costing\CostApprovalService;
use App\Services\Costing\CostReportService;
use App\Support\DecimalNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** I-08 and the cost approval: the auditable cost report of a formula version, its print view, and approving the cost. */
class FormulaCostReportController extends Controller
{
    public function __construct(
        private CostReportService $reportService,
        private CostApprovalService $approvalService,
    ) {}

    /** The report of the latest approved version (or of the latest version when none is approved); `?version=` picks one. */
    public function show(Request $request, Formula $formula): InertiaResponse
    {
        $number = $request->integer('version');
        $version = $number > 0
            ? $formula->versions()->where('version', $number)->firstOrFail()
            : ($this->approvalService->latestApprovedVersion($formula) ?? $formula->versions()->reorder()->orderByDesc('version')->firstOrFail());
        $latest = $this->approvalService->latest($formula);

        return Inertia::render('Formulas/CostReport', [
            'report' => $this->reportService->report($formula, $version),
            'approval' => $latest === null ? null : [
                'id' => $latest->getKey(),
                'unit_cost' => DecimalNormalizer::normalize($latest->unit_cost),
                'version' => $latest->formulaVersion->version,
                'approved_at' => $latest->approved_at->toIso8601String(),
                'approved_by' => $latest->approver?->name,
                'comment' => $latest->comment,
                'stale_reason' => $this->approvalService->staleReason($formula),
            ],
            'versions' => $formula->versions->map(fn ($v): array => ['number' => $v->version, 'status' => $v->status->value])->all(),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => ['sources' => route('sources.search', [], false), 'sourceStore' => route('sources.store', [], false)],
            'canApprove' => $version->isApproved() && $version->getKey() === $this->approvalService->latestApprovedVersion($formula)?->getKey(),
        ]);
    }

    public function approve(ApproveFormulaCostRequest $request, Formula $formula): RedirectResponse
    {
        try {
            $this->approvalService->approve($formula, $request->user(), $request->comment());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('cost_report.approved'));
    }
}
