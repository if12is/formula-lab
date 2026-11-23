<?php

namespace App\Http\Controllers\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\SourceKind;
use App\Exceptions\Domain\EngineException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packaging\CheckPackagingCompatibilityRequest;
use App\Http\Requests\Packaging\StorePackagingCompatibilityRequest;
use App\Models\PackagingCompatibility;
use App\Services\Packaging\PackagingCompatibilityCheckService;
use App\Services\Packaging\PackagingCompatibilityListService;
use App\Services\Packaging\PackagingCompatibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PackagingCompatibilityController extends Controller
{
    public function __construct(
        private PackagingCompatibilityListService $listService,
        private PackagingCompatibilityService $compatibilityService,
        private PackagingCompatibilityCheckService $checkService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Packaging/Compatibility', [
            'list' => $this->listService->list($request, route('packaging.compatibility.necks', ['side' => 'container'], false), route('packaging.compatibility.necks', ['side' => 'closure'], false)),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'containerOptions' => $this->listService->componentOptions([ItemKind::Container]),
            'closureOptions' => $this->listService->componentOptions([ItemKind::Closure, ItemKind::PumpSprayer]),
            'urls' => [
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'check' => route('packaging.compatibility.check', [], false),
            ],
        ]);
    }

    public function store(StorePackagingCompatibilityRequest $request): RedirectResponse
    {
        $this->compatibilityService->declare($request->pairData());

        return redirect()->back(fallback: route('packaging.compatibility.index'))->with('success', __('packaging.compat.declared'));
    }

    public function destroy(PackagingCompatibility $pair): RedirectResponse
    {
        $this->compatibilityService->withdraw($pair);

        return redirect()->back(fallback: route('packaging.compatibility.index'))->with('success', __('packaging.compat.withdrawn'));
    }

    /** JSON: the finishes already declared on one side of a pair, for the list filters. */
    public function necks(string $side): JsonResponse
    {
        abort_unless(in_array($side, ['container', 'closure'], true), 404);

        return response()->json($this->listService->finishOptions($side));
    }

    /** JSON: the engine's answer for the given packaging components. */
    public function check(CheckPackagingCompatibilityRequest $request): JsonResponse
    {
        try {
            return response()->json($this->checkService->check($request->itemIds()));
        } catch (EngineException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
