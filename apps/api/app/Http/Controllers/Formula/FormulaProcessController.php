<?php

namespace App\Http\Controllers\Formula;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formula\UpdateFormulaProcessRequest;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\Formula\FormulaProcessService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FormulaProcessController extends Controller
{
    public function __construct(private FormulaProcessService $processService) {}

    /** The manufacturing steps of one version: editable while it is a draft, read-only after. */
    public function show(Formula $formula, FormulaVersion $version): InertiaResponse
    {
        return Inertia::render('Formulas/Process', $this->processService->panel($formula, $version));
    }

    public function update(UpdateFormulaProcessRequest $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        try {
            $this->processService->replace($version, $request->steps());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('production.process.saved'));
    }
}
