<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\FormulaVersion;
use App\Services\Lab\LabProcessLossService;
use Illuminate\Http\JsonResponse;

class LabProcessLossController extends Controller
{
    public function __construct(private LabProcessLossService $labProcessLossService) {}

    /** JSON for the perfume cost calculator: { losses: { ageing, chilling, filtration } } of a formula version, each a sourced value or null (L-04). */
    public function show(FormulaVersion $version): JsonResponse
    {
        return response()->json(['losses' => $this->labProcessLossService->payload($version)]);
    }
}
