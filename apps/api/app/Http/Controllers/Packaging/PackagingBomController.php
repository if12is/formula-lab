<?php

namespace App\Http\Controllers\Packaging;

use App\Enums\Confidence;
use App\Enums\PackLevel;
use App\Enums\SourceKind;
use App\Exceptions\Domain\EngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packaging\StorePackagingBomRequest;
use App\Http\Requests\Packaging\UpdatePackagingBomRequest;
use App\Models\PackagingBom;
use App\Services\Packaging\PackagingBomCostService;
use App\Services\Packaging\PackagingBomDetailService;
use App\Services\Packaging\PackagingBomListService;
use App\Services\Packaging\PackagingBomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PackagingBomController extends Controller
{
    public function __construct(
        private PackagingBomListService $listService,
        private PackagingBomDetailService $detailService,
        private PackagingBomCostService $costService,
        private PackagingBomService $bomService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Packaging/Boms/Index', [
            'list' => $this->listService->list($request),
            'sanadProductOptions' => $this->listService->sanadProductOptions(),
        ]);
    }

    public function store(StorePackagingBomRequest $request): RedirectResponse
    {
        $bom = $this->bomService->create($request->bomData());

        return redirect()->route('packaging.boms.show', $bom)->with('success', __('packaging.bom.created'));
    }

    public function show(PackagingBom $bom): InertiaResponse
    {
        return Inertia::render('Packaging/Boms/Show', [
            'bom' => $this->detailService->bom($bom),
            'lines' => $this->detailService->lines($bom),
            'componentOptions' => $this->detailService->componentOptions(),
            'sanadProductOptions' => $this->listService->sanadProductOptions(),
            'levelOptions' => PackLevel::options(),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'limits' => [
                'imageMimes' => config('packaging.image.mimes'),
                'imageMaxKb' => config('packaging.image.max_kb'),
            ],
            'urls' => [
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'cost' => route('packaging.boms.cost', $bom, false),
            ],
        ]);
    }

    public function update(UpdatePackagingBomRequest $request, PackagingBom $bom): RedirectResponse
    {
        $this->bomService->update($bom, $request->bomData());

        return back()->with('success', __('packaging.bom.updated'));
    }

    public function destroy(PackagingBom $bom): RedirectResponse
    {
        $this->bomService->delete($bom);

        return redirect()->route('packaging.boms.index')->with('success', __('packaging.bom.deleted'));
    }

    /** JSON: the engine's packaging cost per unit for the saved lines, or what it lacks. */
    public function cost(PackagingBom $bom): JsonResponse
    {
        try {
            return response()->json($this->costService->preview($bom));
        } catch (EngineException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
