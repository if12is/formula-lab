<?php

namespace App\Http\Controllers\Production;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreBatchCheckRequest;
use App\Http\Requests\Production\StoreProductionDeviationRequest;
use App\Http\Requests\Production\UpdateBatchResultsRequest;
use App\Http\Requests\Production\WeighBatchLineRequest;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Services\Production\ProductionBatchDetailService;
use App\Services\Production\ProductionBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProductionBatchController extends Controller
{
    public function __construct(
        private ProductionBatchService $batchService,
        private ProductionBatchDetailService $detailService,
    ) {}

    /** `?superfat_pct=` is the soap recomputation's input (a formulation choice typed by the person, never stored). */
    public function show(Request $request, ProductionBatch $batch): InertiaResponse
    {
        $superfat = $request->query('superfat_pct');

        return Inertia::render('Production/Batches/Show', $this->detailService->forBatch($batch, $request->user(), is_string($superfat) ? $superfat : null));
    }

    public function weigh(WeighBatchLineRequest $request, ProductionBatch $batch, ProductionBatchLine $line): RedirectResponse
    {
        try {
            $this->batchService->weigh($batch, $line, $request->validated());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['actual_quantity' => $exception->getMessage()]);
        }

        return back()->with('success', __('production.batches.weight_saved'));
    }

    public function storeCheck(StoreBatchCheckRequest $request, ProductionBatch $batch): RedirectResponse
    {
        try {
            $this->batchService->check($batch, $request->validated());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['value' => $exception->getMessage()]);
        }

        return back()->with('success', __('production.batches.check_saved'));
    }

    public function storeDeviation(StoreProductionDeviationRequest $request, ProductionBatch $batch): RedirectResponse
    {
        try {
            $this->batchService->deviate($batch, $request->validated(), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('production.batches.deviation_saved'));
    }

    public function updateResults(UpdateBatchResultsRequest $request, ProductionBatch $batch): RedirectResponse
    {
        try {
            $this->batchService->results($batch, $request->validated());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('production.batches.results_saved'));
    }

    public function complete(Request $request, ProductionBatch $batch): RedirectResponse
    {
        try {
            $this->batchService->complete($batch, $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('production.batches.completed'));
    }
}
