<?php

namespace App\Http\Controllers\Calculator;

use App\Enums\Confidence;
use App\Enums\SourceKind;
use App\Http\Controllers\Controller;
use App\Services\Calculator\CalculatorCatalogService;
use App\Services\Calculator\CalculatorMaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CalculatorController extends Controller
{
    public function __construct(
        private CalculatorCatalogService $calculatorCatalogService,
        private CalculatorMaterialService $calculatorMaterialService,
    ) {}

    public function index(): InertiaResponse
    {
        return Inertia::render('Calculators/Index', [
            'groups' => $this->calculatorCatalogService->groups(),
        ]);
    }

    public function show(string $calculator): InertiaResponse
    {
        return Inertia::render($this->calculatorCatalogService->component($calculator), [
            'calculator' => $calculator,
            'urls' => $this->calculatorCatalogService->lookupUrls(),
            'confidenceOptions' => Confidence::options(),
            'sourceKindOptions' => SourceKind::selectableOptions(),
        ]);
    }

    /** JSON for the calculators' material selector: [{ value, label, description, properties }]. */
    public function materials(Request $request): JsonResponse
    {
        return response()->json($this->calculatorMaterialService->search(trim((string) $request->query('q', ''))));
    }
}
