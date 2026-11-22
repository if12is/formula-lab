<?php

namespace App\Http\Controllers\Family;

use App\Http\Controllers\Controller;
use App\Models\ProductFamily;
use App\Services\Family\FamilyDetailService;
use App\Services\Family\FamilyListService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FamilyController extends Controller
{
    public function __construct(
        private FamilyListService $familyListService,
        private FamilyDetailService $familyDetailService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Families/Index', ['list' => $this->familyListService->list($request)]);
    }

    public function show(ProductFamily $family): InertiaResponse
    {
        return Inertia::render('Families/Show', $this->familyDetailService->forFamily($family));
    }
}
