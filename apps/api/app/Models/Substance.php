<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use Database\Factories\SubstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A chemical substance (CAS, MW). Commercial grades are TradeMaterial rows. */
class Substance extends Model
{
    /** @use HasFactory<SubstanceFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['cas', 'inci', 'name_en', 'name_ar', 'mw', 'formula'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mw' => DecimalString::class];
    }

    /** @return HasMany<TradeMaterial, $this> */
    public function tradeMaterials(): HasMany
    {
        return $this->hasMany(TradeMaterial::class);
    }

    /** @return MorphMany<PropertyValue, $this> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'entity');
    }
}
