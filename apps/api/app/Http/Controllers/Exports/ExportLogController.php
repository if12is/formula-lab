<?php

namespace App\Http\Controllers\Exports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\StoreExportLogRequest;
use App\Services\Exports\ExportLogListService;
use App\Services\Exports\ExportLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** U-03: the export log screen, and the call a print preview makes right before it prints. */
class ExportLogController extends Controller
{
    public function __construct(
        private ExportLogListService $listService,
        private ExportLogService $logService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Exports/Index', [
            'list' => $this->listService->list($request, route('production.formulas.options', [], false)),
        ]);
    }

    /** Logs the print and answers with the reference and time the printed page shows. */
    public function store(StoreExportLogRequest $request): JsonResponse
    {
        $log = $this->logService->record($request->logData(), $request->user());

        return response()->json([
            'id' => $log->getKey(),
            'reference' => strtoupper(substr($log->getKey(), 0, 8)),
            'exported_at' => $log->exported_at->toIso8601String(),
        ], 201);
    }
}
