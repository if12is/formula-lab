<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabProtocol;
use App\Services\Lab\LabProtocolService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LabProtocolController extends Controller
{
    public function __construct(private LabProtocolService $labProtocolService) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Lab/Protocols/Index', ['list' => $this->labProtocolService->list($request)]);
    }

    public function show(LabProtocol $protocol): InertiaResponse
    {
        return Inertia::render('Lab/Protocols/Show', $this->labProtocolService->forProtocol($protocol));
    }
}
