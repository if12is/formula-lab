<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use App\Enums\PackLevel;
use Database\Factories\PackagingBomLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One packaging component of a BOM: how many it takes at its level, its piece price (EGP) and reject %. Each figure may be empty
 * (the engine then names the gap); a filled one has the source and confidence this line was entered from.
 */
class PackagingBomLine extends Model
{
    /** @use HasFactory<PackagingBomLineFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'packaging_bom_id', 'trade_material_id', 'level', 'qty_per_unit', 'reject_pct', 'price_egp',
        'source_id', 'confidence', 'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => PackLevel::class,
            'qty_per_unit' => DecimalString::class,
            'reject_pct' => DecimalString::class,
            'price_egp' => DecimalString::class,
            'confidence' => Confidence::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PackagingBom, $this> */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(PackagingBom::class, 'packaging_bom_id');
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
