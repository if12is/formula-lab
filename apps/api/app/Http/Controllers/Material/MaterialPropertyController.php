<?php

namespace App\Http\Controllers\Material;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StorePropertyValueRequest;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Material\PropertyValueService;
use Illuminate\Http\RedirectResponse;

class MaterialPropertyController extends Controller
{
    public function __construct(
        private PropertyValueService $propertyValueService,
    ) {}

    public function store(StorePropertyValueRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->propertyValueService->add($material, $request->validated(), $request->user()?->getKey());

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'properties'])->with('success', __('materials.property_added'));
    }

    public function destroy(TradeMaterial $material, PropertyValue $propertyValue): RedirectResponse
    {
        $this->propertyValueService->remove($propertyValue);

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'properties'])->with('success', __('materials.property_removed'));
    }
}
