<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Dev\ListDemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Test-only playground (routes exist only in local and testing) that exercises the
 * shared list building blocks: ListQuery on the server, ListFilters / useListQuery /
 * EntitySelect on the client. Real index pages follow the same shape (S-03 onwards).
 */
class ListDemoController extends Controller
{
    public function __construct(
        private ListDemoService $listDemoService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Dev/ListDemo', [
            'list' => $this->listDemoService->list($request, route('dev.list-demo.functions')),
            'materialOptions' => $this->listDemoService->materialOptions(trim((string) $request->query('search', ''))),
            'searchUrl' => route('dev.list-demo.materials'),
        ]);
    }

    /** JSON for server-searched selects: [{ value, label, description }]. */
    public function materials(Request $request): JsonResponse
    {
        return response()->json($this->listDemoService->searchMaterials(trim((string) $request->query('q', ''))));
    }

    /** JSON options for the "function" filter: [{ value, label }]. */
    public function functions(): JsonResponse
    {
        return response()->json($this->listDemoService->functionOptions());
    }
}
