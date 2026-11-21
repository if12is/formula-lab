<?php

namespace App\Http\Controllers\Formula;

use App\Http\Controllers\Controller;
use App\Models\Formula;
use App\Services\Lab\FormulaSaltCurveService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FormulaSaltCurveController extends Controller
{
    public function __construct(private FormulaSaltCurveService $formulaSaltCurveService) {}

    /** The formula's own salt curve (O-03): its measured points, and the engine's reading of it for a salt % or a viscosity. */
    public function show(Formula $formula): InertiaResponse
    {
        $points = $this->formulaSaltCurveService->points($formula);

        return Inertia::render('Formulas/SaltCurve', [
            'formula' => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name],
            'points' => $points,
            'curve' => $this->formulaSaltCurveService->enginePayload($formula, $points),
            'sources' => $this->formulaSaltCurveService->sources($points),
            'urls' => [
                'trials' => route('lab.trials.index', ['formula_id' => $formula->getKey()], false),
                'newTrial' => route('lab.trials.create', [], false),
            ],
        ]);
    }
}
