<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PriceListSyncStatus;
use Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named set of per-product prices for some merchants. Its id is the uuid the list has in Sanad (Formula Lab owns it). */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'currency', 'is_active', 'sync_status', 'sync_outbox_id', 'sync_detail',
        'published_hash', 'published_at', 'published_items', 'published_customers',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sync_status' => PriceListSyncStatus::class,
            'sync_detail' => 'array',
            'published_at' => 'datetime',
            'published_items' => 'array',
            'published_customers' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return HasMany<PriceListCustomer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(PriceListCustomer::class);
    }
}
