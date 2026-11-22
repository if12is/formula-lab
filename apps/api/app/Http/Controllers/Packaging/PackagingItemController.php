<?php

namespace App\Http\Controllers\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackagingMaterial;
use App\Enums\SourceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packaging\StorePackagingItemRequest;
use App\Http\Requests\Packaging\UpdatePackagingItemRequest;
use App\Models\TradeMaterial;
use App\Services\Packaging\PackagingItemService;
use App\Services\Packaging\PackagingListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PackagingItemController extends Controller
{
    public function __construct(
        private PackagingListService $listService,
        private PackagingItemService $itemService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Packaging/Index', [
            'list' => $this->listService->list($request, route('packaging.necks', [], false)),
            'kindOptions' => ItemKind::packagingOptions(),
            'materialOptions' => PackagingMaterial::options(),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'supplierOptions' => $this->listService->supplierOptions(),
            'urls' => [
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
            ],
        ]);
    }

    public function store(StorePackagingItemRequest $request): RedirectResponse
    {
        $this->itemService->create($request->packagingData());

        return redirect()->route('packaging.index')->with('success', __('packaging.created'));
    }

    public function update(UpdatePackagingItemRequest $request, TradeMaterial $item): RedirectResponse
    {
        abort_unless($item->item_kind->isPackaging(), 404);

        $this->itemService->update($item, $request->packagingData());

        return back()->with('success', __('packaging.updated'));
    }

    /** JSON options for the neck finish filter: [{ value, label }]. */
    public function necks(): JsonResponse
    {
        return response()->json($this->listService->neckFinishOptions());
    }
}
