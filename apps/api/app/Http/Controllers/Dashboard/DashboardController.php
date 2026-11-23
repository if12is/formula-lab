<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** S-15: the home page with the decision figures. They are computed after the page shell renders (a deferred prop), so the page opens at once. */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'kpis' => Inertia::defer(fn (): array => $this->dashboardService->kpis($user)),
        ]);
    }
}
