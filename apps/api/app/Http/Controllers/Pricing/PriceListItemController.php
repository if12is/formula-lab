<?php

namespace App\Http\Controllers\Pricing;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\SavePriceListItemRequest;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\Pricing\PriceListService;
use Illuminate\Http\RedirectResponse;

class PriceListItemController extends Controller
{
    public function __construct(private PriceListService $priceListService) {}

    public function store(SavePriceListItemRequest $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->priceListService->saveItem($priceList, Formula::query()->findOrFail($data['formula_id']), $data);
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['formula_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('price_lists.items.added'));
    }

    public function update(SavePriceListItemRequest $request, PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        try {
            $this->priceListService->saveItem($priceList, $item->formula, $request->validated(), $item);
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['mode' => $exception->getMessage()]);
        }

        return back()->with('success', __('price_lists.items.updated'));
    }

    public function destroy(PriceList $priceList, PriceListItem $item): RedirectResponse
    {
        $this->priceListService->removeItem($item);

        return back()->with('success', __('price_lists.items.removed'));
    }
}
