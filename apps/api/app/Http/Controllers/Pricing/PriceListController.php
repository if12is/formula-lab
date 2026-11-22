<?php

namespace App\Http\Controllers\Pricing;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StorePriceListRequest;
use App\Http\Requests\Pricing\UpdatePriceListRequest;
use App\Models\PriceList;
use App\Services\Pricing\PriceListDetailService;
use App\Services\Pricing\PriceListListService;
use App\Services\Pricing\PriceListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PriceListController extends Controller
{
    public function __construct(
        private PriceListService $priceListService,
        private PriceListListService $priceListListService,
        private PriceListDetailService $priceListDetailService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('PriceLists/Index', [
            'list' => $this->priceListListService->lists($request),
        ]);
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        $list = $this->priceListService->create($request->validated());

        return redirect()->route('price-lists.show', $list)->with('success', __('price_lists.created'));
    }

    /** The product lines tab. */
    public function show(Request $request, PriceList $priceList): InertiaResponse
    {
        return Inertia::render('PriceLists/Show', [
            'priceList' => $this->priceListDetailService->header($priceList),
            'list' => $this->priceListListService->items($request, $priceList),
            ...$this->priceListDetailService->formProps(),
        ]);
    }

    /** The merchants tab. */
    public function merchants(Request $request, PriceList $priceList): InertiaResponse
    {
        return Inertia::render('PriceLists/Merchants', [
            'priceList' => $this->priceListDetailService->header($priceList),
            'list' => $this->priceListListService->customers($request, $priceList),
            ...$this->priceListDetailService->formProps(),
        ]);
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): RedirectResponse
    {
        $this->priceListService->update($priceList, $request->validated());

        return back()->with('success', __('price_lists.updated'));
    }

    public function publish(PriceList $priceList): RedirectResponse
    {
        try {
            $result = $this->priceListService->publish($priceList);
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $result->unchanged ? __('price_lists.publish.unchanged') : __('price_lists.publish.'.$result->mode));
    }
}
