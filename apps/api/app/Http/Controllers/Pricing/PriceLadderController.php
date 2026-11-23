<?php

namespace App\Http\Controllers\Pricing;

use App\Enums\Confidence;
use App\Enums\SourceKind;
use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\CalculatePriceLadderRequest;
use App\Http\Requests\Tiers\ShowTierRequest;
use App\Models\Formula;
use App\Services\Pricing\PriceLadderService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** S-13: the price ladder of a product, cost to shelf and shelf to maximum cost, with the dollar's effect. */
class PriceLadderController extends Controller
{
    public function __construct(private PriceLadderService $priceLadderService) {}

    public function index(ShowTierRequest $request): InertiaResponse
    {
        $formula = $request->filled('formula') ? Formula::query()->findOrFail($request->validated('formula')) : null;

        return Inertia::render('PriceLadder/Index', [
            'product' => $formula === null ? null : $this->priceLadderService->product($formula),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => [
                'products' => route('price-lists.products.search', [], false),
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'calculate' => route('price-ladder.calculate', [], false),
            ],
            'maxFxChanges' => (int) config('pricing.ladder_max_fx_changes'),
        ]);
    }

    public function calculate(CalculatePriceLadderRequest $request): JsonResponse
    {
        $formula = Formula::query()->findOrFail($request->validated('formula_id'));

        try {
            return response()->json($this->priceLadderService->calculate($formula, $request->validated()));
        } catch (BusinessRuleViolationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
