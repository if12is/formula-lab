<?php

namespace App\Models;

use Database\Factories\SanadCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * READ-ONLY CACHE of a Sanad customer (contract 8.5): the fields pricing needs and nothing financial. Customers live in
 * Sanad; Formula Lab never creates or edits one, it only reads this row and points a price list at its uuid.
 */
class SanadCustomer extends Model
{
    /** @use HasFactory<SanadCustomerFactory> */
    use HasFactory;

    protected $table = 'sanad_customers_cache';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'uuid', 'tenant_id', 'code', 'name', 'name_en', 'is_active', 'is_wholesale', 'price_list_uuid',
        'updated_at', 'deleted', 'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_wholesale' => 'boolean',
            'updated_at' => 'datetime',
            'deleted' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** @return HasOne<PriceListCustomer, $this> The Formula Lab list this customer is assigned to, if any. */
    public function assignment(): HasOne
    {
        return $this->hasOne(PriceListCustomer::class, 'customer_uuid');
    }
}
