<?php

namespace App\Http\Controllers\Formula;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formula\CompareFormulaVersionsRequest;
use App\Http\Requests\Formula\StoreFormulaVersionRequest;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\Formula\FormulaEditorService;
use App\Services\Formula\FormulaHistoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FormulaVersionController extends Controller
{
    public function __construct(
        private FormulaEditorService $formulaEditorService,
        private FormulaHistoryService $formulaHistoryService,
    ) {}

    public function edit(Formula $formula, FormulaVersion $version): InertiaResponse
    {
        return Inertia::render('Formulas/Editor', [
            ...$this->formulaEditorService->forVersion($formula, $version),
            'urls' => FormulaController::editorUrls(),
        ]);
    }

    /** Saves the lines as the next version, derived from the one being edited. */
    public function store(StoreFormulaVersionRequest $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        try {
            $saved = $this->formulaEditorService->saveVersion(
                $formula,
                $version,
                $request->lineData(),
                $request->validated('reason'),
                $request->balanceEvents(),
                $request->user(),
            );
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('formulas.versions.edit', [$formula, $saved->version])
            ->with('success', __('formulas.version_saved', ['version' => $saved->version]));
    }

    /** The difference between two versions; with no query it compares the last two. */
    public function compare(CompareFormulaVersionsRequest $request, Formula $formula): InertiaResponse
    {
        $numbers = $formula->versions()->reorder()->orderByDesc('version')->pluck('version')->all();
        abort_if($numbers === [], 404);
        $to = (int) ($request->validated('to') ?? $numbers[0]);
        $from = (int) ($request->validated('from') ?? ($numbers[1] ?? $numbers[0]));

        $fromVersion = $formula->versions()->where('version', $from)->firstOrFail();
        $toVersion = $formula->versions()->where('version', $to)->firstOrFail();

        return Inertia::render('Formulas/Compare', [
            'formula' => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name],
            'from' => $from,
            'to' => $to,
            'history' => $this->formulaHistoryService->history($formula),
            'diff' => $this->formulaHistoryService->compare($fromVersion, $toVersion),
        ]);
    }
}
