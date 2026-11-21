<?php

namespace App\Http\Controllers\Api\Sanad;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sanad\ApplySanadChangesRequest;
use App\Services\Sanad\SanadChangesService;
use Illuminate\Http\JsonResponse;

class ChangesController extends Controller
{
    public function __construct(private SanadChangesService $changes) {}

    public function __invoke(ApplySanadChangesRequest $request): JsonResponse
    {
        $result = $this->changes->apply(
            $request->device(),
            array_values($request->validated('items')),
            $request->validated('cursor'),
        );

        return response()->json($result->toArray());
    }
}
