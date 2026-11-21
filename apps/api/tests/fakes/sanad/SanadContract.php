<?php

namespace Tests\fakes\sanad;

use PHPUnit\Framework\Assert;

/** Assertions on the section 4 / 5 shapes of orchestration/context/sanad-contract-v1.md, shared by fake and mock tests. */
final class SanadContract
{
    private const DECIMAL = '/^-?\d+(\.\d+)?$/';

    private const ISO_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /** @param  array<string, mixed>  $page */
    public static function assertPage(array $page): void
    {
        Assert::assertArrayHasKey('data', $page);
        Assert::assertIsList($page['data']);
        Assert::assertArrayHasKey('next_cursor', $page);
        Assert::assertTrue($page['next_cursor'] === null || is_string($page['next_cursor']));
    }

    /** @param  array<string, mixed>  $row */
    public static function assertDecimalString(array $row, string $key, bool $nullable = false): void
    {
        Assert::assertArrayHasKey($key, $row);
        if ($nullable && $row[$key] === null) {
            return;
        }
        Assert::assertIsString($row[$key], "{$key} must be a decimal string");
        Assert::assertMatchesRegularExpression(self::DECIMAL, $row[$key], $key);
    }

    /** @param  array<string, mixed>  $row */
    public static function assertTombstone(array $row): void
    {
        Assert::assertTrue($row['deleted']);
        Assert::assertMatchesRegularExpression(self::ISO_UTC, $row['deleted_at']);
        Assert::assertArrayNotHasKey('name', $row);
    }

    /** @param  array<string, mixed>  $row */
    public static function assertProduct(array $row): void
    {
        foreach (['uuid', 'name', 'item_kind', 'is_sellable', 'uom', 'units', 'status', 'updated_at'] as $key) {
            Assert::assertArrayHasKey($key, $row, $key);
        }
        Assert::assertContains($row['item_kind'], ['merchandise', 'raw_material', 'packaging', 'finished_good']);
        Assert::assertIsBool($row['is_sellable']);
        Assert::assertArrayHasKey('name', $row['uom']);
        Assert::assertMatchesRegularExpression(self::ISO_UTC, $row['updated_at']);
        foreach (['cost_price', 'sell_price', 'tax_rate', 'stock'] as $key) {
            self::assertDecimalString($row, $key);
        }
        foreach (['wholesale_price', 'guide_price'] as $key) {
            self::assertDecimalString($row, $key, nullable: true);
        }
        foreach ($row['units'] as $unit) {
            self::assertDecimalString($unit, 'conversion_factor');
        }
    }

    /** @param  array<string, mixed>  $row */
    public static function assertSupplier(array $row): void
    {
        foreach (['uuid', 'name', 'is_active', 'payment_terms', 'updated_at'] as $key) {
            Assert::assertArrayHasKey($key, $row, $key);
        }
        Assert::assertTrue($row['payment_terms'] === null || is_int($row['payment_terms']));
        Assert::assertMatchesRegularExpression(self::ISO_UTC, $row['updated_at']);
    }

    /** @param  array<string, mixed>  $row */
    public static function assertPurchase(array $row): void
    {
        foreach (['uuid', 'invoice_number', 'supplier_uuid', 'purchase_date', 'status', 'items', 'updated_at'] as $key) {
            Assert::assertArrayHasKey($key, $row, $key);
        }
        foreach (['subtotal', 'tax_amount', 'discount_amount', 'total'] as $key) {
            self::assertDecimalString($row, $key);
        }
        foreach ($row['items'] as $item) {
            Assert::assertArrayHasKey('product_uuid', $item);
            foreach (['quantity', 'base_quantity', 'unit_cost', 'subtotal', 'received_quantity'] as $key) {
                self::assertDecimalString($item, $key);
            }
        }
    }

    /** @param  array<string, mixed>  $row */
    public static function assertStock(array $row): void
    {
        foreach (['product_uuid', 'branch_uuid', 'uom', 'updated_at'] as $key) {
            Assert::assertArrayHasKey($key, $row, $key);
        }
        self::assertDecimalString($row, 'quantity');
    }
}
