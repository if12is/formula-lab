<?php

namespace App\Models;

use App\Enums\Confidence;
use App\Enums\DataVerification;
use App\Enums\Regulator;
use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One product family of the registry (K-01); reference data loaded from the engine's data file. */
class ProductFamily extends Model
{
    /** @use HasFactory<ProductFamilyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['code', 'group', 'name_ar', 'name_en', 'regulator', 'ifra_category', 'required_calculations', 'measured_properties', 'tax_regime', 'notes_key', 'source_id', 'confidence', 'verification', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'regulator' => Regulator::class,
            'required_calculations' => 'array',
            'measured_properties' => 'array',
            'confidence' => Confidence::class,
            'verification' => DataVerification::class,
            'position' => 'integer',
        ];
    }

    /** The name in the active language. */
    public function displayName(): string
    {
        return app()->getLocale() === 'en' ? $this->name_en : $this->name_ar;
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasMany<FormulaTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(FormulaTemplate::class)->orderBy('position');
    }

    /** @return HasMany<Formula, $this> */
    public function formulas(): HasMany
    {
        return $this->hasMany(Formula::class);
    }
}
