<?php

namespace App\Models;

use App\Casts\DecimalString;
use Database\Factories\SanadPurchaseLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** READ-ONLY CACHE of a Sanad PurchaseItem: overwritten by sync, never edited locally. */
class SanadPurchaseLine extends Model
{
    /** @use HasFactory<SanadPurchaseLineFactory> */
    use HasFactory;

    protected $primaryKey = 'sanad_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'sanad_uuid', 'tenant_id', 'sanad_purchase_uuid', 'sanad_supplier_uuid', 'sanad_product_uuid', 'item_id',
        'purchase_date', 'purchase_status', 'quantity', 'purchase_uom', 'base_quantity', 'received_quantity',
        'unit_cost', 'discount_amount', 'tax_amount', 'subtotal', 'currency', 'batch_number',
        'expiry_date', 'production_date', 'sanad_updated_at', 'synced_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'production_date' => 'date:Y-m-d',
            'quantity' => DecimalString::class,
            'base_quantity' => DecimalString::class,
            'received_quantity' => DecimalString::class,
            'unit_cost' => DecimalString::class,
            'discount_amount' => DecimalString::class,
            'tax_amount' => DecimalString::class,
            'subtotal' => DecimalString::class,
            'sanad_updated_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
