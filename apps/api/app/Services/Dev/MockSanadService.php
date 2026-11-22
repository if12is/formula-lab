<?php

namespace App\Services\Dev;

use App\Support\Dev\StringDecimal;
use App\Support\RequestFingerprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stand-in for Sanad's /api/v1/manufacturing/* (G-10), same contract, backed by the JSON files in
 * tests/fakes/sanad/fixtures plus a small overlay kept in the cache (upserted products, processed orders).
 * Fixture keys that start with "_" are private notes and never leave the service.
 * Dev and test tool only: not a model of Sanad's stock rules.
 */
final class MockSanadService
{
    private const CACHE_PREFIX = 'sanad-mock:';

    private const OVERLAY_TTL_SECONDS = 86400;

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(string $resource, ?string $cursor, ?string $updatedSince, ?string $kind = null): array
    {
        $rows = match ($resource) {
            'products' => $this->products(),
            'customers' => $this->customers(),
            default => $this->fixture($resource),
        };

        if ($kind !== null && $kind !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['item_kind'] ?? $row['_item_kind'] ?? null) === $kind));
        }
        if ($updatedSince !== null && $updatedSince !== '') {
            $since = Carbon::parse($updatedSince)->utc();
            $rows = array_values(array_filter($rows, fn (array $row): bool => $this->stamp($row)->greaterThanOrEqualTo($since)));
        }

        usort($rows, fn (array $a, array $b): int => [$this->stamp($a)->format('Y-m-d H:i:s'), $this->key($a)] <=> [$this->stamp($b)->format('Y-m-d H:i:s'), $this->key($b)]);

        if (($decoded = $this->decodeCursor($cursor)) !== null) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => [$this->stamp($row)->format('Y-m-d H:i:s'), $this->key($row)] > $decoded));
        }

        $size = config('sanad.mock.page_size');
        $page = array_slice($rows, 0, $size);

        return [
            'data' => array_map($this->publicRow(...), $page),
            'next_cursor' => count($rows) > $size ? $this->encodeCursor(end($page)) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated contract 4.2 body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function upsertProduct(string $uuid, array $data): array
    {
        $overlay = $this->overlay('products');
        $created = ! array_key_exists($uuid, $overlay) && ! $this->fixtureHas('products', $uuid);
        $existing = $overlay[$uuid] ?? $this->fixtureRow('products', $uuid) ?? [];
        $updatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $row = array_merge($existing, [
            'uuid' => $uuid,
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? $existing['name_en'] ?? null,
            'sku' => $data['sku'] ?? $existing['sku'] ?? null,
            'barcode' => $data['barcode'] ?? $existing['barcode'] ?? null,
            'item_kind' => $data['item_kind'],
            'is_sellable' => (bool) ($data['is_sellable'] ?? $data['item_kind'] === 'finished_good'),
            'uom' => ['uuid' => null, 'name' => $data['uom'] ?? $existing['uom']['name'] ?? null, 'base' => true],
            'units' => $existing['units'] ?? [],
            'category' => $existing['category'] ?? null,
            'cost_price' => $this->money($data['cost_price'] ?? $existing['cost_price'] ?? '0'),
            'sell_price' => $this->money($data['sell_price'] ?? $existing['sell_price'] ?? '0'),
            'wholesale_price' => isset($data['wholesale_price']) ? $this->money($data['wholesale_price']) : ($existing['wholesale_price'] ?? null),
            'guide_price' => isset($data['guide_price']) ? $this->money($data['guide_price']) : ($existing['guide_price'] ?? null),
            'tax_rate' => $this->money($data['tax_rate'] ?? $existing['tax_rate'] ?? '0'),
            'stock' => $existing['stock'] ?? '0.000000',
            'status' => 'published',
            'updated_at' => $updatedAt,
        ]);
        $this->remember('products', $uuid, $row);

        return ['status' => $created ? 201 : 200, 'body' => [
            'uuid' => $uuid, 'item_kind' => $row['item_kind'], 'is_sellable' => $row['is_sellable'], 'updated_at' => $updatedAt,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data  validated contract 4.5 body
     * @return array<string, mixed>|null null when the product is unknown
     */
    public function updateWholesalePrices(string $uuid, array $data): ?array
    {
        $row = $this->overlay('products')[$uuid] ?? $this->fixtureRow('products', $uuid);
        if ($row === null || isset($row['deleted'])) {
            return null;
        }

        foreach (['wholesale_price', 'guide_price', 'sell_price'] as $field) {
            if (isset($data[$field])) {
                $row[$field] = $this->money($data[$field]);
            }
        }
        $row['updated_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->remember('products', $uuid, $row);

        return [
            'uuid' => $uuid,
            'wholesale_price' => $row['wholesale_price'],
            'guide_price' => $row['guide_price'],
            'sell_price' => $row['sell_price'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * All-or-nothing consume (contract 4.3). A repeat with the same body returns the first result.
     *
     * @param  array<string, mixed>  $body  validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function consume(string $externalId, array $body): array
    {
        $hash = RequestFingerprint::hash($body);
        $order = $this->overlay('orders')[$externalId] ?? [];
        if (isset($order['consume'])) {
            return $order['consume']['hash'] === $hash
                ? ['status' => 200, 'body' => $order['consume']['response']]
                : ['status' => 409, 'body' => ['message' => __('sanad.mock.order_conflict')]];
        }

        $stock = collect($this->fixture('stock'))->keyBy('product_uuid');
        $errors = [];
        $shortfalls = [];
        foreach ($body['lines'] as $index => $line) {
            $product = $this->productRow($line['product_uuid']);
            if ($product === null) {
                $errors["lines.{$index}.product_uuid"] = [__('sanad.mock.unknown_product', ['product' => $line['product_uuid']])];

                continue;
            }
            $available = (string) ($stock[$line['product_uuid']]['quantity'] ?? '0');
            if (StringDecimal::compare($line['quantity'], $available) > 0) {
                $errors["lines.{$index}.quantity"] = [__('sanad.mock.insufficient_stock_line')];
                $shortfalls[] = ['product_uuid' => $line['product_uuid'], 'required' => $line['quantity'], 'available' => $available];
            }
        }
        if ($errors !== []) {
            return ['status' => 422, 'body' => ['message' => __('sanad.mock.insufficient_stock'), 'errors' => $errors, 'shortfalls' => $shortfalls]];
        }

        $lines = [];
        $cents = 0;
        foreach ($body['lines'] as $line) {
            $product = $this->productRow($line['product_uuid']);
            $total = StringDecimal::round(StringDecimal::multiply($line['quantity'], (string) $product['cost_price']), 2);
            $cents += (int) str_replace('.', '', $total);
            $batch = $stock[$line['product_uuid']]['batches'][0] ?? null;
            $lines[] = [
                'product_uuid' => $line['product_uuid'],
                'quantity' => StringDecimal::round($line['quantity'], 6),
                'unit_cost' => $this->money($product['cost_price']),
                'total_cost' => $total,
                'batches' => $batch === null ? [] : [[
                    'batch_uuid' => $batch['batch_uuid'],
                    'batch_number' => $batch['batch_number'],
                    'quantity' => StringDecimal::round($line['quantity'], 6),
                ]],
            ];
        }
        $response = [
            'external_id' => $externalId,
            'status' => 'consumed',
            'lines' => $lines,
            'total_cost' => substr_replace(str_pad((string) $cents, 3, '0', STR_PAD_LEFT), '.', -2, 0),
        ];

        $order['consume'] = ['hash' => $hash, 'response' => $response];
        $this->remember('orders', $externalId, $order);

        return ['status' => 200, 'body' => $response];
    }

    /**
     * @param  array<string, mixed>  $body  validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function receive(string $externalId, array $body): array
    {
        $hash = RequestFingerprint::hash($body);
        $order = $this->overlay('orders')[$externalId] ?? [];
        if (isset($order['receive'])) {
            return $order['receive']['hash'] === $hash
                ? ['status' => 200, 'body' => $order['receive']['response']]
                : ['status' => 409, 'body' => ['message' => __('sanad.mock.order_conflict')]];
        }

        $product = $this->productRow($body['finished_good_uuid']);
        if ($product === null || ($product['item_kind'] ?? null) !== 'finished_good') {
            return ['status' => 422, 'body' => [
                'message' => __('sanad.mock.not_finished_good'),
                'errors' => ['finished_good_uuid' => [__('sanad.mock.not_finished_good')]],
            ]];
        }
        if (! isset($order['consume'])) {
            return ['status' => 409, 'body' => ['message' => __('sanad.mock.not_consumed')]];
        }

        $response = [
            'external_id' => $externalId,
            'status' => 'received',
            'product_batch_uuid' => (string) Str::uuid(),
            'stock_movement_uuid' => (string) Str::uuid(),
            'quantity' => StringDecimal::round($body['quantity'], 6),
        ];
        $order['receive'] = ['hash' => $hash, 'response' => $response];
        $this->remember('orders', $externalId, $order);

        return ['status' => 200, 'body' => $response];
    }

    /**
     * Upserts a price list by uuid with its item rows (contract 8.2). Atomic: an unknown product refuses the whole request and the
     * list is not created. Omitted `items` leave the stored ones alone; a null price removes a product. Prices are stored at 2 dp.
     *
     * @param  array<string, mixed>  $data  validated body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function upsertPriceList(string $uuid, array $data): array
    {
        $existing = $this->overlay('price_lists')[$uuid] ?? null;
        $applied = $this->applyPriceListItems($existing['items'] ?? [], (array) ($data['items'] ?? []));
        if ($applied['errors'] !== []) {
            return $this->priceListInvalid($applied['errors']);
        }

        $updatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $list = [
            'uuid' => $uuid,
            'name' => $data['name'],
            'currency' => 'EGP',
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing['is_active'] ?? true),
            'items' => $applied['stored'],
            'updated_at' => $updatedAt,
        ];
        $this->remember('price_lists', $uuid, $list);

        return ['status' => $existing === null ? 201 : 200, 'body' => $this->priceListBody($list, $existing === null, $applied)];
    }

    /**
     * Items only (contract 8.3). 404 for an unknown list.
     *
     * @param  array<string, mixed>  $data  validated body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function upsertPriceListItems(string $uuid, array $data): array
    {
        $list = $this->overlay('price_lists')[$uuid] ?? null;
        if ($list === null) {
            return ['status' => 404, 'body' => ['message' => __('sanad.mock.price_list_not_found')]];
        }

        $applied = $this->applyPriceListItems($list['items'], (array) $data['items']);
        if ($applied['errors'] !== []) {
            return $this->priceListInvalid($applied['errors']);
        }

        $list['items'] = $applied['stored'];
        $list['updated_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->remember('price_lists', $uuid, $list);

        return ['status' => 200, 'body' => $this->priceListBody($list, false, $applied)];
    }

    /**
     * Assigns a customer to a list, or detaches it with null (contract 8.4). Returns the customer payload of 8.5.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function assignCustomerPriceList(string $customerUuid, ?string $listUuid): array
    {
        $customer = collect($this->customers())->firstWhere('uuid', $customerUuid);
        if ($customer === null || isset($customer['deleted'])) {
            return ['status' => 404, 'body' => ['message' => __('sanad.mock.customer_not_found')]];
        }

        if ($listUuid !== null) {
            $list = $this->overlay('price_lists')[$listUuid] ?? null;
            if ($list === null) {
                return ['status' => 422, 'body' => ['message' => __('sanad.mock.price_list_not_found'), 'errors' => ['price_list_uuid' => [__('sanad.mock.price_list_not_found')]]]];
            }
            if (! $list['is_active']) {
                return ['status' => 422, 'body' => ['message' => __('sanad.mock.price_list_inactive'), 'errors' => ['price_list_uuid' => [__('sanad.mock.price_list_inactive')]]]];
            }
        }

        if (($customer['price_list_uuid'] ?? null) !== $listUuid) {
            $customer['price_list_uuid'] = $listUuid;
            $customer['updated_at'] = now()->utc()->format('Y-m-d\TH:i:s\Z');
            $this->remember('customers', $customerUuid, $customer);
        }

        return ['status' => 200, 'body' => $customer];
    }

    /** Forgets everything the mock remembered (upserted products, processed orders, price lists, customer assignments). */
    public function reset(): void
    {
        Cache::forget(self::CACHE_PREFIX.'products');
        Cache::forget(self::CACHE_PREFIX.'orders');
        Cache::forget(self::CACHE_PREFIX.'price_lists');
        Cache::forget(self::CACHE_PREFIX.'customers');
    }

    /**
     * @param  array<string, string>  $stored  product uuid => price (2 dp)
     * @param  list<array<string, mixed>>  $rows  request item rows
     * @return array{stored: array<string, string>, upserted: list<array{product_uuid: string, price: string}>, removed: list<string>, errors: array<string, list<string>>}
     */
    private function applyPriceListItems(array $stored, array $rows): array
    {
        $errors = [];
        $upserted = [];
        $removed = [];

        foreach ($rows as $index => $row) {
            $productUuid = (string) $row['product_uuid'];
            if ($this->productRow($productUuid) === null) {
                $errors["items.{$index}.product_uuid"] = [__('sanad.mock.unknown_product', ['product' => $productUuid])];

                continue;
            }
            if ($row['price'] === null) {
                unset($stored[$productUuid]);
                $removed[] = $productUuid;
            } else {
                $stored[$productUuid] = $this->money($row['price']);
                $upserted[] = ['product_uuid' => $productUuid, 'price' => $stored[$productUuid]];
            }
        }

        return ['stored' => $stored, 'upserted' => $upserted, 'removed' => $removed, 'errors' => $errors];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{status: int, body: array<string, mixed>}
     */
    private function priceListInvalid(array $errors): array
    {
        return ['status' => 422, 'body' => ['message' => __('sanad.mock.price_list_invalid'), 'errors' => $errors]];
    }

    /**
     * The 8.2 response: `items` / `removed` echo the rows of this request; `items_count` is the list's total.
     *
     * @param  array<string, mixed>  $list
     * @param  array{upserted: list<array{product_uuid: string, price: string}>, removed: list<string>}  $applied
     * @return array<string, mixed>
     */
    private function priceListBody(array $list, bool $created, array $applied): array
    {
        return [
            'uuid' => $list['uuid'],
            'name' => $list['name'],
            'currency' => 'EGP',
            'is_active' => $list['is_active'],
            'created' => $created,
            'items' => $applied['upserted'],
            'removed' => $applied['removed'],
            'items_count' => count($list['items']),
            'updated_at' => $list['updated_at'],
        ];
    }

    /** @return list<array<string, mixed>> fixtures overlaid with customers whose assignment changed */
    private function customers(): array
    {
        $rows = collect($this->fixture('customers'))->keyBy('uuid');
        foreach ($this->overlay('customers') as $uuid => $row) {
            $rows[$uuid] = $row;
        }

        return $rows->values()->all();
    }

    /** @return list<array<string, mixed>> fixtures overlaid with upserted products */
    private function products(): array
    {
        $rows = collect($this->fixture('products'))->keyBy('uuid');
        foreach ($this->overlay('products') as $uuid => $row) {
            $rows[$uuid] = $row;
        }

        return $rows->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function productRow(string $uuid): ?array
    {
        $row = collect($this->products())->firstWhere('uuid', $uuid);

        return $row === null || isset($row['deleted']) ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function fixture(string $name): array
    {
        $path = config('sanad.mock.fixtures_path')."/{$name}.json";

        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
    }

    /** @return array<string, mixed>|null */
    private function fixtureRow(string $name, string $uuid): ?array
    {
        return collect($this->fixture($name))->firstWhere('uuid', $uuid);
    }

    private function fixtureHas(string $name, string $uuid): bool
    {
        return $this->fixtureRow($name, $uuid) !== null;
    }

    /** @return array<string, array<string, mixed>> */
    private function overlay(string $bucket): array
    {
        return (array) Cache::get(self::CACHE_PREFIX.$bucket, []);
    }

    /** @param  array<string, mixed>  $value */
    private function remember(string $bucket, string $key, array $value): void
    {
        $all = $this->overlay($bucket);
        $all[$key] = $value;
        Cache::put(self::CACHE_PREFIX.$bucket, $all, self::OVERLAY_TTL_SECONDS);
    }

    /** @param  array<string, mixed>  $row */
    private function stamp(array $row): Carbon
    {
        return Carbon::parse((string) ($row['updated_at'] ?? $row['deleted_at'] ?? '1970-01-01T00:00:00Z'))->utc();
    }

    /** @param  array<string, mixed>  $row */
    private function key(array $row): string
    {
        return (string) ($row['uuid'] ?? $row['product_uuid'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        return array_filter($row, fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /** @param  array<string, mixed>  $row */
    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode($this->stamp($row)->format('Y-m-d H:i:s').'|'.$this->key($row)), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($raw) || ! str_contains($raw, '|')) {
            return null;
        }
        [$stamp, $uuid] = explode('|', $raw, 2);

        return [$stamp, $uuid];
    }

    private function money(mixed $value): string
    {
        return StringDecimal::round((string) $value, 2);
    }
}
