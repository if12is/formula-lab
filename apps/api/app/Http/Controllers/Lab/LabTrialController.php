<?php

namespace App\Http\Controllers\Lab;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\StoreLabMeasurementRequest;
use App\Http\Requests\Lab\StoreLabTrialRequest;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use App\Services\Lab\LabLookupService;
use App\Services\Lab\LabTrialDetailService;
use App\Services\Lab\LabTrialListService;
use App\Services\Lab\LabTrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LabTrialController extends Controller
{
    public function __construct(
        private LabTrialService $labTrialService,
        private LabTrialListService $labTrialListService,
        private LabTrialDetailService $labTrialDetailService,
        private LabLookupService $labLookupService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Lab/Trials/Index', [
            'list' => $this->labTrialListService->list($request, route('lab.formulas.options', [], false)),
        ]);
    }

    /** The entry form; `?formula_version=<id>` (from the formula editor) preselects the version. */
    public function create(Request $request): InertiaResponse
    {
        $id = $request->query('formula_version');
        $version = is_string($id) && Str::isUuid($id) ? FormulaVersion::query()->find($id) : null;

        return Inertia::render('Lab/Trials/Create', [
            ...$this->labTrialDetailService->formChoices($version),
            'urls' => [
                'store' => route('lab.trials.store', [], false),
                'versions' => route('lab.formula-versions.search', [], false),
                'index' => route('lab.trials.index', [], false),
            ],
        ]);
    }

    public function store(StoreLabTrialRequest $request): RedirectResponse
    {
        $trial = $this->labTrialService->start($request->formulaVersion(), $request->trialData(), $request->user());

        return redirect()->route('lab.trials.show', $trial)->with('success', __('lab.trials.created'));
    }

    public function show(LabTrial $trial): InertiaResponse
    {
        return Inertia::render('Lab/Trials/Show', $this->labTrialDetailService->forTrial($trial));
    }

    public function storeMeasurement(StoreLabMeasurementRequest $request, LabTrial $trial): RedirectResponse
    {
        try {
            $this->labTrialService->record($trial, $request->measurementData(), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['value' => $exception->getMessage()]);
        }

        return back()->with('success', __('lab.trials.measurement_saved'));
    }

    public function complete(Request $request, LabTrial $trial): RedirectResponse
    {
        try {
            $this->labTrialService->complete($trial, $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('lab.trials.completed'));
    }

    /** JSON options for the formula filter: [{ value, label }]. */
    public function formulaOptions(): JsonResponse
    {
        return response()->json($this->labTrialListService->formulaOptions());
    }

    /** JSON typeahead of formula versions for the entry form: GET ?q=text. */
    public function versionSearch(Request $request): JsonResponse
    {
        return response()->json($this->labLookupService->versions((string) $request->query('q', '')));
    }
}
