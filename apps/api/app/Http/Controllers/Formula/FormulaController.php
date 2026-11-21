<?php

namespace App\Http\Controllers\Formula;

use App\Http\Controllers\Controller;
use App\Http\Requests\Formula\StoreFormulaRequest;
use App\Http\Requests\Formula\UpdateFormulaIfraCategoryRequest;
use App\Models\Formula;
use App\Services\Formula\FormulaEditorService;
use App\Services\Formula\FormulaListService;
use App\Services\Formula\FormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FormulaController extends Controller
{
    public function __construct(
        private FormulaListService $formulaListService,
        private FormulaEditorService $formulaEditorService,
        private FormulaService $formulaService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Formulas/Index', [
            'list' => $this->formulaListService->list($request, route('formulas.product-types', [], false)),
        ]);
    }

    public function create(): InertiaResponse
    {
        return Inertia::render('Formulas/Editor', [
            ...$this->formulaEditorService->blank(),
            'urls' => $this->editorUrls(),
        ]);
    }

    public function store(StoreFormulaRequest $request): RedirectResponse
    {
        $created = $this->formulaService->create($request->formulaData(), $request->lineData(), $request->user());

        return redirect()
            ->route('formulas.versions.edit', [$created['formula'], $created['version']->version])
            ->with('success', __('formulas.created'));
    }

    /** The product's IFRA category, saved on the formula from the compliance panel. */
    public function updateIfraCategory(UpdateFormulaIfraCategoryRequest $request, Formula $formula): RedirectResponse
    {
        $this->formulaService->setIfraCategory($formula, $request->validated('ifra_category'));

        return back()->with('success', __('compliance.ifra.saved'));
    }

    /** A formula opens at its latest version. */
    public function show(Formula $formula): RedirectResponse
    {
        $latest = (int) $formula->versions()->reorder()->max('version');

        return redirect()->route('formulas.versions.edit', [$formula, $latest]);
    }

    /** JSON options for the product type filter and suggestions: [{ value, label }]. */
    public function productTypes(): JsonResponse
    {
        return response()->json($this->formulaListService->productTypeOptions());
    }

    /** @return array<string, string> */
    public static function editorUrls(): array
    {
        return [
            'materials' => route('materials.search', [], false),
            'quickMaterial' => route('materials.draft.store', [], false),
            'substances' => route('substances.search', [], false),
            'substanceStore' => route('substances.store', [], false),
            'functions' => route('materials.functions', [], false),
            'productTypes' => route('formulas.product-types', [], false),
        ];
    }
}
