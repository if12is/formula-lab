<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\SearchPriceListOptionsRequest;
use App\Services\Pricing\PriceListOptionService;
use Illuminate\Http\JsonResponse;

/** JSON for the selectors of the price list screens: `[{ value, label, description }]`. */
class PriceListOptionController extends Controller
{
    public function __construct(private PriceListOptionService $options) {}

    public function customers(SearchPriceListOptionsRequest $request): JsonResponse
    {
        return response()->json($this->options->customers((string) $request->validated('q')));
    }

    public function products(SearchPriceListOptionsRequest $request): JsonResponse
    {
        return response()->json($this->options->products((string) $request->validated('q')));
    }
}
