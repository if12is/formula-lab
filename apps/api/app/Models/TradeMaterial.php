<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\TradeMaterialStatus;
use Database\Factories\TradeMaterialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A commercial grade (e.g. "SLES 70%"). sanad_*_uuid are soft references: Sanad owns
 * the product and supplier records.
 */
class TradeMaterial extends Model
{
    /** @use HasFactory<TradeMaterialFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'substance_id', 'sanad_product_uuid', 'sanad_supplier_uuid',
        'trade_name', 'grade', 'function', 'is_mixture', 'status', 'composition_complete', 'is_fragrance',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_mixture' => 'boolean',
            'composition_complete' => 'boolean',
            'is_fragrance' => 'boolean',
            'status' => TradeMaterialStatus::class,
        ];
    }

    /** @return BelongsTo<Substance, $this> */
    public function substance(): BelongsTo
    {
        return $this->belongsTo(Substance::class);
    }

    /** @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /** @return HasMany<MaterialComposition, $this> */
    public function compositions(): HasMany
    {
        return $this->hasMany(MaterialComposition::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return MorphMany<PropertyValue, $this> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'entity');
    }
}
