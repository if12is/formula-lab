<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\MaterialCompositionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One component of a trade material's composition: a CAS number and/or INCI name at an exact % w/w, with its source. */
class MaterialComposition extends Model
{
    /** @use HasFactory<MaterialCompositionFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['trade_material_id', 'cas', 'inci', 'pct_ww', 'source_id', 'confidence', 'valid_from', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pct_ww' => DecimalString::class,
            'confidence' => Confidence::class,
            'valid_from' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function tradeMaterial(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
