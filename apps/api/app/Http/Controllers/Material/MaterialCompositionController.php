<?php

namespace App\Http\Controllers\Material;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreMaterialCompositionRequest;
use App\Http\Requests\Material\UpdateCompositionFlagsRequest;
use App\Http\Requests\Material\UpdateMaterialCompositionRequest;
use App\Models\MaterialComposition;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialCompositionService;
use Illuminate\Http\RedirectResponse;

class MaterialCompositionController extends Controller
{
    public function __construct(
        private MaterialCompositionService $compositionService,
    ) {}

    public function store(StoreMaterialCompositionRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->compositionService->add($material, $request->componentData(), $request->user()?->getKey());

        return $this->back($material, 'materials.composition.added');
    }

    public function update(UpdateMaterialCompositionRequest $request, TradeMaterial $material, MaterialComposition $composition): RedirectResponse
    {
        $this->compositionService->update($composition, $request->componentData());

        return $this->back($material, 'materials.composition.updated');
    }

    public function destroy(TradeMaterial $material, MaterialComposition $composition): RedirectResponse
    {
        $this->compositionService->remove($composition);

        return $this->back($material, 'materials.composition.removed');
    }

    /** Whether the listed components are the whole material, and whether it is a fragrance oil. */
    public function flags(UpdateCompositionFlagsRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->compositionService->setFlags($material, $request->validated());

        return $this->back($material, 'materials.composition.flags_saved');
    }

    private function back(TradeMaterial $material, string $messageKey): RedirectResponse
    {
        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'composition'])->with('success', __($messageKey));
    }
}
