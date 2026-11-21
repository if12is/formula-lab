<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Sanad\SanadItemKind;
use Database\Factories\SanadProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** READ-ONLY CACHE of a Sanad Product (raw material, packaging or finished good). Sanad wins. */
class SanadProduct extends Model
{
    /** @use HasFactory<SanadProductFactory> */
    use HasFactory;

    protected $table = 'sanad_products_cache';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    /** updated_at is Sanad's own timestamp, not a Laravel one. */
    public $timestamps = false;

    protected $fillable = [
        'uuid', 'tenant_id', 'name', 'name_en', 'sku', 'barcode', 'item_kind', 'is_sellable', 'uom',
        'units', 'cost_price', 'tax_rate', 'updated_at', 'deleted', 'synced_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'item_kind' => SanadItemKind::class,
            'is_sellable' => 'boolean',
            'units' => 'array',
            'cost_price' => DecimalString::class,
            'tax_rate' => DecimalString::class,
            'updated_at' => 'datetime',
            'deleted' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
