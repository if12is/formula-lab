<?php

namespace App\Http\Controllers\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\StoreSourceRequest;
use App\Services\Material\SourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function __construct(
        private SourceService $sourceService,
    ) {}

    /** JSON for the source typeahead: [{ value, label, description }]. */
    public function search(Request $request): JsonResponse
    {
        return response()->json($this->sourceService->search(trim((string) $request->query('q', ''))));
    }

    /** Inline creation from the property and price forms; answers with the new option. */
    public function store(StoreSourceRequest $request): JsonResponse
    {
        $source = $this->sourceService->create($request->validated());

        return response()->json($this->sourceService->option($source), 201);
    }
}
