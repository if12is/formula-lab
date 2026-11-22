<?php

namespace App\Http\Controllers\Pricing;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\AssignPriceListCustomerRequest;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\SanadCustomer;
use App\Services\Pricing\PriceListService;
use Illuminate\Http\RedirectResponse;

class PriceListCustomerController extends Controller
{
    public function __construct(private PriceListService $priceListService) {}

    public function store(AssignPriceListCustomerRequest $request, PriceList $priceList): RedirectResponse
    {
        try {
            $this->priceListService->assignCustomer($priceList, SanadCustomer::query()->findOrFail($request->validated('customer_uuid')));
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['customer_uuid' => $exception->getMessage()]);
        }

        return back()->with('success', __('price_lists.customers.added'));
    }

    public function destroy(PriceList $priceList, PriceListCustomer $customer): RedirectResponse
    {
        $this->priceListService->unassignCustomer($customer);

        return back()->with('success', __('price_lists.customers.removed'));
    }
}
