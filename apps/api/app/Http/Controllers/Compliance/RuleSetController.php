<?php

namespace App\Http\Controllers\Compliance;

use App\Enums\SourceKind;
use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Compliance\PublishRuleSetRequest;
use App\Http\Requests\Compliance\StoreRuleSetRequest;
use App\Http\Requests\Compliance\StoreRuleSetVersionRequest;
use App\Http\Requests\Compliance\UpdateRuleSetRequest;
use App\Models\RuleSet;
use App\Services\Compliance\RuleSetDetailService;
use App\Services\Compliance\RuleSetListService;
use App\Services\Compliance\RuleSetService;
use App\Support\Compliance\RuleDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RuleSetController extends Controller
{
    public function __construct(
        private RuleSetListService $ruleSetListService,
        private RuleSetDetailService $ruleSetDetailService,
        private RuleSetService $ruleSetService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Compliance/Index', [
            'list' => $this->ruleSetListService->list($request, route('compliance.product-types', [], false)),
            'urls' => $this->lookupUrls(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
        ]);
    }

    public function show(Request $request, RuleSet $ruleSet): InertiaResponse
    {
        return Inertia::render('Compliance/Show', [
            ...$this->ruleSetDetailService->detail($ruleSet, $request),
            'urls' => $this->lookupUrls(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'ruleDefaults' => ['messageKey' => RuleDocument::CUSTOM_MESSAGE_KEY],
        ]);
    }

    public function store(StoreRuleSetRequest $request): RedirectResponse
    {
        try {
            $ruleSet = $this->ruleSetService->create($request->ruleSetData(), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()]);
        }

        return redirect()->route('compliance.show', $ruleSet)->with('success', __('compliance.created'));
    }

    public function update(UpdateRuleSetRequest $request, RuleSet $ruleSet): RedirectResponse
    {
        try {
            $this->ruleSetService->update($ruleSet, $request->ruleSetData());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()]);
        }

        return back()->with('success', __('compliance.updated'));
    }

    public function destroy(RuleSet $ruleSet): RedirectResponse
    {
        try {
            $this->ruleSetService->delete($ruleSet);
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('compliance.index')->with('success', __('compliance.deleted'));
    }

    /** Validates the rule set with the engine and freezes it; the form carries the hash the person reviewed. */
    public function publish(PublishRuleSetRequest $request, RuleSet $ruleSet): RedirectResponse
    {
        try {
            $this->ruleSetService->publish($ruleSet, $request->validated('expected_hash'));
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('compliance.published'));
    }

    /** A published rule set is never edited: a new draft version of it is. */
    public function newVersion(StoreRuleSetVersionRequest $request, RuleSet $ruleSet): RedirectResponse
    {
        try {
            $copy = $this->ruleSetService->newVersion($ruleSet, $request->validated('version'), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()]);
        }

        return redirect()->route('compliance.show', $copy)->with('success', __('compliance.version_created'));
    }

    /** JSON options for the product type filter and the rule form: [{ value, label }]. */
    public function productTypes(): JsonResponse
    {
        return response()->json($this->ruleSetListService->productTypeOptions());
    }

    /** @return array<string, string> */
    private function lookupUrls(): array
    {
        return [
            'sources' => route('sources.search', [], false),
            'sourceStore' => route('sources.store', [], false),
            'productTypes' => route('formulas.product-types', [], false),
            'materials' => route('materials.search', [], false),
        ];
    }
}
