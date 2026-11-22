<?php

namespace App\Support\Sanad;

use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadItemKind;
use Illuminate\Validation\Rule;

/** Validation rules for the `payload` of a pushed Sanad change (contract section 5). Decimals must be strings. */
final class ChangePayloadRules
{
    private const DECIMAL = '/^-?\d+(\.\d+)?$/';

    /** @return array<string, mixed> */
    public static function for(SanadEntity $entity): array
    {
        return match ($entity) {
            SanadEntity::Product => self::product(),
            SanadEntity::Supplier => self::supplier(),
            SanadEntity::Customer => self::customer(),
            SanadEntity::Purchase => self::purchase(),
            SanadEntity::Batch => self::batch(),
            SanadEntity::StockMovement => [],
        };
    }

    /** @return list<mixed> */
    private static function decimal(): array
    {
        return ['nullable', 'string', 'regex:'.self::DECIMAL];
    }

    /** @return array<string, mixed> */
    private static function product(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'item_kind' => ['required', Rule::enum(SanadItemKind::class)],
            'is_sellable' => ['nullable', 'boolean'],
            'uom' => ['nullable', 'array'],
            'uom.name' => ['nullable', 'string', 'max:100'],
            'units' => ['nullable', 'array'],
            'cost_price' => self::decimal(),
            'tax_rate' => self::decimal(),
        ];
    }

    /** @return array<string, mixed> */
    private static function supplier(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> Contract 8.5: only what pricing needs. */
    private static function customer(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'is_wholesale' => ['nullable', 'boolean'],
            'price_list_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, mixed> */
    private static function purchase(): array
    {
        return [
            'supplier_uuid' => ['nullable', 'uuid'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'string', 'max:30'],
            'items' => ['present', 'array'],
            'items.*.uuid' => ['required', 'uuid'],
            'items.*.product_uuid' => ['required', 'uuid'],
            'items.*.uom_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => self::decimal(),
            'items.*.base_quantity' => self::decimal(),
            'items.*.received_quantity' => self::decimal(),
            'items.*.unit_cost' => self::decimal(),
            'items.*.discount_amount' => self::decimal(),
            'items.*.tax_amount' => self::decimal(),
            'items.*.subtotal' => self::decimal(),
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.production_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    private static function batch(): array
    {
        return [
            'batch_number' => ['required', 'string', 'max:100'],
            'product_uuid' => ['required', 'uuid'],
            'purchase_uuid' => ['nullable', 'uuid'],
            'supplier_uuid' => ['nullable', 'uuid'],
            'cost_price' => self::decimal(),
            'initial_quantity' => self::decimal(),
            'remaining_quantity' => self::decimal(),
            'base_quantity' => self::decimal(),
            'remaining_base_quantity' => self::decimal(),
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'production_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'max:30'],
        ];
    }
}
