<?php

namespace Tests\fakes\sanad;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Http::fake() helpers that answer like Sanad Desktop's /api/v1/manufacturing/* (contract section 4).
 * Call SanadFake::use() once per test, then register what each route returns:
 *
 *     SanadFake::use();
 *     SanadFake::products([SanadFake::product(['item_kind' => 'packaging'])]);
 *     SanadFake::fail('consume', 422, 'insufficient', ['shortfalls' => [...]]);
 *
 * Unregistered routes answer 404. Payload builders return the contract section 5 shapes with decimal strings;
 * fixtures are the JSON files in tests/fakes/sanad/fixtures, shared with the mock server.
 */
final class SanadFake
{
    public const BASE_URL = 'http://sanad.test';

    public const TOKEN = 'sanad-fake-token';

    private const PATH = '#/api/v1/manufacturing';

    /** @var array<string, array{0: string, 1: string}> route name => [method, path regex] */
    private const ROUTES = [
        'products' => ['GET', '/products$'],
        'suppliers' => ['GET', '/suppliers$'],
        'purchases' => ['GET', '/purchases$'],
        'stock' => ['GET', '/stock$'],
        'upsertProduct' => ['PUT', '/products/[^/]+$'],
        'wholesalePrices' => ['PUT', '/products/[^/]+/wholesale-prices$'],
        'consume' => ['POST', '/production-orders/[^/]+/consume$'],
        'receive' => ['POST', '/production-orders/[^/]+/receive$'],
    ];

    /** @var array<string, callable|ResponseSequence|PromiseInterface> */
    private static array $responders = [];

    /** Points SanadClient at the fake and clears every registered route. */
    public static function use(): void
    {
        self::$responders = [];
        config(['sanad.client.base_url' => self::BASE_URL, 'sanad.client.token' => self::TOKEN]);

        Http::fake(function (Request $request) {
            foreach (self::ROUTES as $name => [$method, $regex]) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                if ($request->method() === $method && preg_match(self::PATH.$regex.'#', $path) === 1 && isset(self::$responders[$name])) {
                    $responder = self::$responders[$name];

                    return is_callable($responder) ? $responder($request) : $responder;
                }
            }

            return Http::response(['message' => 'No Sanad fake registered for '.$request->method().' '.$request->url()], 404);
        });
    }

    /**
     * One page of products. Honours the `kind` filter like Sanad does.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function products(array $rows, ?string $nextCursor = null): void
    {
        self::collection('products', [$rows], $nextCursor, filterKind: true);
    }

    /**
     * Several pages of products; page N is returned for cursor `page-N` (the first page needs no cursor).
     *
     * @param  list<list<array<string, mixed>>>  $pages
     */
    public static function productPages(array $pages): void
    {
        self::collection('products', $pages, filterKind: true);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function suppliers(array $rows, ?string $nextCursor = null): void
    {
        self::collection('suppliers', [$rows], $nextCursor);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function purchases(array $rows, ?string $nextCursor = null): void
    {
        self::collection('purchases', [$rows], $nextCursor);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function stock(array $rows, ?string $nextCursor = null): void
    {
        self::collection('stock', [$rows], $nextCursor);
    }

    /** PUT products/{uuid}: echoes uuid, item_kind and is_sellable of the request unless $response overrides. */
    public static function upsertProduct(array $response = [], int $status = 200): void
    {
        self::respond('upsertProduct', function (Request $request) use ($response, $status) {
            $body = $request->data();

            return Http::response($response + [
                'uuid' => Str::afterLast((string) parse_url($request->url(), PHP_URL_PATH), '/'),
                'item_kind' => $body['item_kind'] ?? 'raw_material',
                'is_sellable' => (bool) ($body['is_sellable'] ?? false),
                'updated_at' => '2026-11-21T09:30:00Z',
            ], $status);
        });
    }

    /** POST production-orders/{id}/consume: consumed, one cost-free line per requested line unless $response is given. */
    public static function consume(?array $response = null, int $status = 200): void
    {
        self::respond('consume', function (Request $request) use ($response, $status) {
            $externalId = self::externalId($request);

            return Http::response($response ?? [
                'external_id' => $externalId,
                'status' => 'consumed',
                'lines' => array_map(fn (array $line): array => [
                    'product_uuid' => $line['product_uuid'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => '41.20',
                    'total_cost' => '2575.00',
                    'batches' => [],
                ], $request->data()['lines'] ?? []),
                'total_cost' => '2575.00',
            ], $status);
        });
    }

    /** POST production-orders/{id}/receive. */
    public static function receive(?array $response = null, int $status = 200): void
    {
        self::respond('receive', function (Request $request) use ($response, $status) {
            return Http::response($response ?? [
                'external_id' => self::externalId($request),
                'status' => 'received',
                'product_batch_uuid' => '00000000-0000-4000-8000-0000000000b1',
                'stock_movement_uuid' => '00000000-0000-4000-8000-0000000000c1',
                'quantity' => $request->data()['quantity'] ?? '0.000000',
            ], $status);
        });
    }

    /** PUT products/{uuid}/wholesale-prices: echoes the prices of the request. */
    public static function wholesalePrices(array $response = []): void
    {
        self::respond('wholesalePrices', function (Request $request) use ($response) {
            $body = $request->data();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return Http::response($response + [
                'uuid' => Str::of($path)->beforeLast('/wholesale-prices')->afterLast('/')->toString(),
                'wholesale_price' => $body['wholesale_price'] ?? null,
                'guide_price' => $body['guide_price'] ?? null,
                'sell_price' => $body['sell_price'] ?? null,
                'updated_at' => '2026-11-21T09:30:00Z',
            ]);
        });
    }

    /**
     * Makes a route fail with a contract error body (`{"message": ..., ...extra}`).
     *
     * @param  array<string, mixed>  $extra  e.g. `errors`, `shortfalls`
     * @param  array<string, string>  $headers  e.g. `Retry-After`
     */
    public static function fail(string $route, int $status, string $message = 'error', array $extra = [], array $headers = []): void
    {
        self::respond($route, fn () => Http::response(['message' => $message] + $extra, $status, $headers));
    }

    /** Registers any responder for a route: a callable(Request), a Http::sequence() or a response. */
    public static function respond(string $route, callable|ResponseSequence|PromiseInterface $responder): void
    {
        if (! isset(self::ROUTES[$route])) {
            throw new InvalidArgumentException("Unknown Sanad fake route [{$route}].");
        }

        self::$responders[$route] = $responder;
    }

    /** @return array<string, mixed> A section 5 product row (raw material by default). */
    public static function product(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => (string) Str::uuid(),
            'name' => 'Glycerin',
            'name_en' => null,
            'sku' => null,
            'barcode' => null,
            'item_kind' => 'raw_material',
            'is_sellable' => false,
            'uom' => ['uuid' => null, 'name' => 'kg', 'base' => true],
            'units' => [],
            'category' => null,
            'cost_price' => '41.20',
            'sell_price' => '0.00',
            'wholesale_price' => null,
            'guide_price' => null,
            'tax_rate' => '14.00',
            'stock' => '120.000000',
            'status' => 'published',
            'updated_at' => '2026-11-21T09:30:00Z',
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => (string) Str::uuid(),
            'code' => null,
            'name' => 'Chem Co',
            'name_en' => null,
            'tax_number' => null,
            'payment_terms' => 30,
            'is_active' => true,
            'updated_at' => '2026-11-21T09:30:00Z',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function purchase(array $overrides = [], array $items = []): array
    {
        return $overrides + [
            'uuid' => (string) Str::uuid(),
            'invoice_number' => 'PUR-0001',
            'reference_number' => null,
            'supplier_uuid' => (string) Str::uuid(),
            'purchase_date' => '2026-11-20',
            'status' => 'received',
            'subtotal' => '2575.00',
            'tax_amount' => '360.50',
            'discount_amount' => '0.00',
            'total' => '2935.50',
            'branch_code' => 'MAIN',
            'updated_at' => '2026-11-21T09:30:00Z',
            'items' => $items === [] ? [self::purchaseItem()] : $items,
        ];
    }

    /** @return array<string, mixed> */
    public static function purchaseItem(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => (string) Str::uuid(),
            'product_uuid' => (string) Str::uuid(),
            'uom_name' => 'jerrycan 25 kg',
            'quantity' => '2.00',
            'base_quantity' => '50.00',
            'unit_cost' => '1287.50',
            'discount_amount' => '0.00',
            'tax_rate' => '14.00',
            'tax_amount' => '360.50',
            'subtotal' => '2575.00',
            'received_quantity' => '2.00',
            'batch_number' => 'L-7731',
            'production_date' => null,
            'expiry_date' => '2028-06-30',
        ];
    }

    /** @return array{uuid: string, deleted: true, deleted_at: string} */
    public static function tombstone(string $uuid, string $deletedAt = '2026-11-21T10:00:00Z', ?string $itemKind = null): array
    {
        return ['uuid' => $uuid, 'deleted' => true, 'deleted_at' => $deletedAt] + ($itemKind === null ? [] : ['_item_kind' => $itemKind]);
    }

    /**
     * Rows of a fixture file (products, suppliers, purchases, stock) as the mock server serves them.
     *
     * @return list<array<string, mixed>>
     */
    public static function fixture(string $name): array
    {
        return (array) json_decode((string) file_get_contents(__DIR__."/fixtures/{$name}.json"), true);
    }

    /**
     * @param  list<list<array<string, mixed>>>  $pages
     */
    private static function collection(string $route, array $pages, ?string $nextCursor = null, bool $filterKind = false): void
    {
        self::respond($route, function (Request $request) use ($pages, $nextCursor, $filterKind) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $index = isset($query['cursor']) && preg_match('/^page-(\d+)$/', $query['cursor'], $match) === 1 ? (int) $match[1] - 1 : 0;
            $rows = $pages[$index] ?? [];

            if ($filterKind && ($query['kind'] ?? '') !== '') {
                $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['item_kind'] ?? $row['_item_kind'] ?? null) === $query['kind']));
            }
            $rows = array_map(fn (array $row): array => array_filter($row, fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY), $rows);

            $next = count($pages) > 1 ? ($index + 1 < count($pages) ? 'page-'.($index + 2) : null) : $nextCursor;

            return Http::response(['data' => $rows, 'next_cursor' => $next]);
        });
    }

    private static function externalId(Request $request): string
    {
        return Str::of((string) parse_url($request->url(), PHP_URL_PATH))->beforeLast('/')->afterLast('/')->toString();
    }
}
