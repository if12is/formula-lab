<?php

namespace App\Http\Controllers\Api\Sanad;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sanad\AckSanadOutboxRequest;
use App\Http\Requests\Sanad\ListSanadOutboxRequest;
use App\Services\Sanad\SanadOutboxService;
use Illuminate\Http\JsonResponse;

class OutboxController extends Controller
{
    public function __construct(private SanadOutboxService $outbox) {}

    public function index(ListSanadOutboxRequest $request): JsonResponse
    {
        $limit = $request->validated('limit');

        return response()->json($this->outbox->page(
            $request->device(),
            $request->validated('cursor'),
            $limit === null ? null : (int) $limit,
        )->toArray());
    }

    public function ack(AckSanadOutboxRequest $request): JsonResponse
    {
        return response()->json($this->outbox->acknowledge($request->device(), array_values($request->validated('acks'))));
    }
}
