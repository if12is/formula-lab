<?php

namespace App\Http\Controllers\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\StoreSubstanceRequest;
use App\Services\Material\SubstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubstanceController extends Controller
{
    public function __construct(
        private SubstanceService $substanceService,
    ) {}

    /** JSON for the substance typeahead: [{ value, label, description }]. */
    public function search(Request $request): JsonResponse
    {
        return response()->json($this->substanceService->search(trim((string) $request->query('q', ''))));
    }

    /** Inline creation from the material form; answers with the new option. */
    public function store(StoreSubstanceRequest $request): JsonResponse
    {
        $substance = $this->substanceService->create($request->validated());

        return response()->json($this->substanceService->option($substance), 201);
    }
}
