<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\FormulaVersion;
use App\Services\Lab\LabMeasuredDensityService;
use Illuminate\Http\JsonResponse;

class LabDensityController extends Controller
{
    public function __construct(private LabMeasuredDensityService $labMeasuredDensityService) {}

    /** JSON for the litre/kilo calculator: { density: <sourced density> | null } of a formula version (O-04). */
    public function show(FormulaVersion $version): JsonResponse
    {
        return response()->json(['density' => $this->labMeasuredDensityService->payload($version)]);
    }
}
