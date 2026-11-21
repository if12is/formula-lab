<?php

namespace App\Http\Controllers\Material;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StorePriceRequest;
use App\Models\Price;
use App\Models\TradeMaterial;
use App\Services\Material\PriceService;
use Illuminate\Http\RedirectResponse;

class MaterialPriceController extends Controller
{
    public function __construct(
        private PriceService $priceService,
    ) {}

    public function store(StorePriceRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->priceService->add($material, $request->validated(), $request->user()?->getKey());

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'prices'])->with('success', __('materials.price_added'));
    }

    public function destroy(TradeMaterial $material, Price $price): RedirectResponse
    {
        $this->priceService->remove($price);

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'prices'])->with('success', __('materials.price_removed'));
    }
}
