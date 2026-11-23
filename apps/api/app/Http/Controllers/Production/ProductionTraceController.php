<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\TradeMaterial;
use App\Services\Production\ProductionTraceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProductionTraceController extends Controller
{
    public function __construct(private ProductionTraceService $traceService) {}

    /** The recall report: every batch that used a raw-material lot (`?lot=`), optionally of one material (`?material=`). */
    public function index(Request $request): InertiaResponse
    {
        $lot = trim((string) $request->query('lot', ''));
        $material = $request->query('material');
        $materialId = is_string($material) && Str::isUuid($material) ? $material : null;

        return Inertia::render('Production/Trace/Index', [
            'filters' => ['lot' => $lot, 'material' => $materialId],
            'material' => $materialId === null ? null : TradeMaterial::query()->whereKey($materialId)->value('trade_name'),
            'searched' => $lot !== '',
            'rows' => $this->traceService->byLot($lot, $materialId),
            'urls' => ['self' => route('production.trace', [], false)],
        ]);
    }
}
