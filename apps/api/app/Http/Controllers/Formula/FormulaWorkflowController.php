<?php

namespace App\Http\Controllers\Formula;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\ApproveFormulaVersionRequest;
use App\Http\Requests\Lab\RejectFormulaVersionRequest;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\Formula\FormulaWorkflowService;
use Illuminate\Http\RedirectResponse;

/** The signed workflow moves of one formula version (O-02). The panel on the editor page shows and triggers them. */
class FormulaWorkflowController extends Controller
{
    public function __construct(private FormulaWorkflowService $formulaWorkflowService) {}

    public function sendToLab(ApproveFormulaVersionRequest $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        return $this->move(fn () => $this->formulaWorkflowService->sendToLab($version, $request->user(), $request->validated('comment')), 'formulas.workflow.sent_to_lab');
    }

    public function approve(ApproveFormulaVersionRequest $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        return $this->move(fn () => $this->formulaWorkflowService->approve($version, $request->user(), $request->validated('comment')), 'formulas.workflow.approved');
    }

    public function reject(RejectFormulaVersionRequest $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        return $this->move(fn () => $this->formulaWorkflowService->reject($version, $request->validated('reason'), $request->user()), 'formulas.workflow.rejected');
    }

    /** @param  callable(): mixed  $move */
    private function move(callable $move, string $successKey): RedirectResponse
    {
        try {
            $move();
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __($successKey));
    }
}
