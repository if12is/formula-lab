<?php

namespace App\Http\Controllers\Material;

use App\Enums\Confidence;
use App\Enums\FunctionalClass;
use App\Enums\SourceKind;
use App\Enums\TradeMaterialStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreDraftMaterialRequest;
use App\Http\Requests\Material\StoreMaterialRequest;
use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialDetailService;
use App\Services\Material\MaterialListService;
use App\Services\Material\MaterialOptionService;
use App\Services\Material\MaterialService;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MaterialController extends Controller
{
    /** The tabs of the material page; the first is the default. */
    private const TABS = ['properties', 'prices', 'composition', 'audit'];

    public function __construct(
        private MaterialListService $materialListService,
        private MaterialDetailService $materialDetailService,
        private MaterialOptionService $materialOptionService,
        private MaterialService $materialService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Materials/Index', [
            'list' => $this->materialListService->list($request, route('materials.functions', [], false)),
            'statusOptions' => TradeMaterialStatus::options(),
            'functionalClassOptions' => FunctionalClass::options(),
            'urls' => $this->lookupUrls(),
        ]);
    }

    public function store(StoreMaterialRequest $request): RedirectResponse
    {
        $material = $this->materialService->create($request->materialData());

        return redirect()->route('materials.show', $material)->with('success', __('materials.created'));
    }

    public function show(Request $request, TradeMaterial $material): InertiaResponse
    {
        $tab = $request->query('tab');

        return Inertia::render('Materials/Show', [
            ...$this->materialDetailService->detail($material),
            'tab' => in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
            'statusOptions' => TradeMaterialStatus::options(),
            'functionalClassOptions' => FunctionalClass::options(),
            'confidenceOptions' => Confidence::options(),
            'currencyOptions' => Currencies::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => $this->lookupUrls(),
        ]);
    }

    public function update(UpdateMaterialRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->materialService->update($material, $request->materialData());

        return back()->with('success', __('materials.updated'));
    }

    /** JSON for the material typeahead: [{ value, label, description, status, prices }]. */
    public function search(Request $request): JsonResponse
    {
        return response()->json($this->materialOptionService->search(trim((string) $request->query('q', ''))));
    }

    /** JSON options for the "function" filter and suggestions: [{ value, label }]. */
    public function functions(): JsonResponse
    {
        return response()->json($this->materialListService->functionOptions());
    }

    /** D-09: a material typed into the formula editor. Always a draft; a price typed with it is an L7 estimate. */
    public function storeDraft(StoreDraftMaterialRequest $request): JsonResponse
    {
        $created = $this->materialService->createDraftFromEditor($request->materialData(), $request->pricePerKg(), $request->user()?->getKey());

        $option = $this->materialOptionService->forMaterials(new EloquentCollection([$created['material']]))[$created['material']->getKey()];

        return response()->json($option, 201);
    }

    /** @return array<string, string> */
    private function lookupUrls(): array
    {
        return [
            'substances' => route('substances.search', [], false),
            'sources' => route('sources.search', [], false),
            'functions' => route('materials.functions', [], false),
            'substanceStore' => route('substances.store', [], false),
            'sourceStore' => route('sources.store', [], false),
        ];
    }
}
