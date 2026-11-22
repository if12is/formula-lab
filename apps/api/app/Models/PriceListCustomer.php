<?php

namespace App\Models;

use Database\Factories\PriceListCustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant (Sanad customer, by uuid) assigned to a price list. A customer is on at most one list. */
class PriceListCustomer extends Model
{
    /** @use HasFactory<PriceListCustomerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['price_list_id', 'customer_uuid'];

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<SanadCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(SanadCustomer::class, 'customer_uuid', 'uuid');
    }
}
