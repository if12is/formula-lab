<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dev\ConsumeMockSanadOrderRequest;
use App\Http\Requests\Dev\ListMockSanadRequest;
use App\Http\Requests\Dev\ReceiveMockSanadOrderRequest;
use App\Http\Requests\Dev\UpdateMockSanadWholesalePricesRequest;
use App\Http\Requests\Dev\UpsertMockSanadProductRequest;
use App\Services\Dev\MockSanadService;
use Illuminate\Http\JsonResponse;

/** Test-only Sanad stand-in (G-10). Routes exist in local and testing only. */
class MockSanadController extends Controller
{
    public function __construct(private MockSanadService $mock) {}

    public function products(ListMockSanadRequest $request): JsonResponse
    {
        return $this->list('products', $request);
    }

    public function suppliers(ListMockSanadRequest $request): JsonResponse
    {
        return $this->list('suppliers', $request);
    }

    public function purchases(ListMockSanadRequest $request): JsonResponse
    {
        return $this->list('purchases', $request);
    }

    public function stock(ListMockSanadRequest $request): JsonResponse
    {
        return $this->list('stock', $request);
    }

    public function upsertProduct(UpsertMockSanadProductRequest $request, string $uuid): JsonResponse
    {
        $result = $this->mock->upsertProduct($uuid, $request->validated());

        return response()->json($result['body'], $result['status']);
    }

    public function updateWholesalePrices(UpdateMockSanadWholesalePricesRequest $request, string $uuid): JsonResponse
    {
        $result = $this->mock->updateWholesalePrices($uuid, $request->validated());

        return $result === null
            ? response()->json(['message' => __('sanad.mock.unknown_product', ['product' => $uuid])], 404)
            : response()->json($result);
    }

    public function consume(ConsumeMockSanadOrderRequest $request, string $externalId): JsonResponse
    {
        $result = $this->mock->consume($externalId, $request->validated());

        return response()->json($result['body'], $result['status']);
    }

    public function receive(ReceiveMockSanadOrderRequest $request, string $externalId): JsonResponse
    {
        $result = $this->mock->receive($externalId, $request->validated());

        return response()->json($result['body'], $result['status']);
    }

    private function list(string $resource, ListMockSanadRequest $request): JsonResponse
    {
        return response()->json($this->mock->list(
            $resource,
            $request->validated('cursor'),
            $request->validated('updated_since'),
            $request->validated('kind'),
        ));
    }
}
