<?php

namespace App\Http\Controllers\Tiers;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tiers\SaveOptimizedVersionRequest;
use App\Http\Requests\Tiers\SaveTierProfileRequest;
use App\Http\Requests\Tiers\ShowTierRequest;
use App\Http\Requests\Tiers\SolveTierRequest;
use App\Models\Formula;
use App\Services\Tiers\OptimizedVersionService;
use App\Services\Tiers\TierPageService;
use App\Services\Tiers\TierProfileService;
use App\Services\Tiers\TierSolveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** S-05: a product, a tier and a target price in, the optimizer's formula and what it depends on out. */
class TierController extends Controller
{
    public function __construct(
        private TierPageService $tierPageService,
        private TierProfileService $tierProfileService,
        private TierSolveService $tierSolveService,
        private OptimizedVersionService $optimizedVersionService,
    ) {}

    public function index(ShowTierRequest $request): InertiaResponse
    {
        $formula = $request->filled('formula') ? Formula::query()->findOrFail($request->validated('formula')) : null;

        return Inertia::render('Tiers/Index', $this->tierPageService->props($formula, $request->tier()));
    }

    public function saveProfile(SaveTierProfileRequest $request): RedirectResponse
    {
        $formula = Formula::query()->findOrFail($request->validated('formula_id'));
        $this->tierProfileService->save($formula->product_type, $request->tier(), $request->validated());

        return redirect()->route('tiers.index', ['formula' => $formula->getKey(), 'tier' => $request->validated('tier')])->with('success', __('tiers.saved'));
    }

    public function solve(SolveTierRequest $request): JsonResponse
    {
        $formula = Formula::query()->findOrFail($request->validated('formula_id'));

        try {
            return response()->json($this->tierSolveService->solve($formula, $request->tier(), $request->validated()));
        } catch (BusinessRuleViolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    /** J-09: the optimizer's answer saved as a new draft version of the product, with where it came from. */
    public function saveVersion(SaveOptimizedVersionRequest $request): JsonResponse
    {
        $formula = Formula::query()->findOrFail($request->validated('formula_id'));

        try {
            $version = $this->optimizedVersionService->saveDraft($formula, $request->tier(), $request->validated(), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['formula_id' => [$exception->getMessage()]]], 422);
        }

        return response()->json([
            'number' => $version->version,
            'status' => $version->status->value,
            'hash' => $version->hash,
            'url' => route('formulas.versions.edit', [$formula, $version->version], false),
        ], 201);
    }
}
