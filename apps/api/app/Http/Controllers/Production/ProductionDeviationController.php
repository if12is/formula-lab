<?php

namespace App\Http\Controllers\Production;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\DecideProductionDeviationRequest;
use App\Models\ProductionDeviation;
use App\Services\Production\ProductionBatchService;
use Illuminate\Http\RedirectResponse;

class ProductionDeviationController extends Controller
{
    public function __construct(private ProductionBatchService $batchService) {}

    public function decide(DecideProductionDeviationRequest $request, ProductionDeviation $deviation): RedirectResponse
    {
        try {
            $this->batchService->decide($deviation, $request->approves(), $request->validated('note'), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $request->approves() ? __('production.batches.deviation_approved') : __('production.batches.deviation_rejected'));
    }
}
