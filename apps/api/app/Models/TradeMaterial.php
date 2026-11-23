<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use App\Enums\Ionicity;
use App\Enums\ItemKind;
use App\Enums\PackagingMaterial;
use App\Enums\TradeMaterialStatus;
use Database\Factories\TradeMaterialFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'trade_name', 'grade', 'function', 'functional_classes', 'ionicity', 'is_mixture', 'status', 'composition_complete', 'is_fragrance',
        'item_kind', 'packaging_material', 'nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g', 'neck_finish', 'packaging_source_id', 'packaging_confidence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'functional_classes' => 'array',
            'ionicity' => Ionicity::class,
            'is_mixture' => 'boolean',
            'composition_complete' => 'boolean',
            'is_fragrance' => 'boolean',
            'status' => TradeMaterialStatus::class,
            'item_kind' => ItemKind::class,
            'packaging_material' => PackagingMaterial::class,
            'nominal_capacity_ml' => DecimalString::class,
            'brimful_capacity_ml' => DecimalString::class,
            'unit_weight_g' => DecimalString::class,
            'packaging_confidence' => Confidence::class,
        ];
    }

    /** @return BelongsTo<Source, $this> Where the packaging spec (capacity, weight, neck finish) comes from. */
    public function packagingSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'packaging_source_id');
    }

    /**
     * Ingredients only (the formula editor, calculators and the materials library).
     *
     * @param  Builder<TradeMaterial>  $query
     */
    public function scopeIngredients(Builder $query): void
    {
        $query->where('item_kind', ItemKind::RawMaterial->value);
    }

    /**
     * Packaging components only.
     *
     * @param  Builder<TradeMaterial>  $query
     */
    public function scopePackaging(Builder $query): void
    {
        $query->where('item_kind', '<>', ItemKind::RawMaterial->value);
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

    /** @return HasMany<IfraCertificate, $this> */
    public function ifraCertificates(): HasMany
    {
        return $this->hasMany(IfraCertificate::class)->orderByDesc('issued_on')->orderBy('id');
    }

    /** @return MorphMany<PropertyValue, $this> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'entity');
    }
}
