<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reference price quote. Actual purchase prices live in Sanad (sanad_purchase_lines cache). */
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'trade_material_id', 'sanad_supplier_uuid', 'amount', 'currency', 'per_unit',
        'quoted_on', 'fx_rate', 'fx_source', 'source_id', 'confidence', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => DecimalString::class,
            'fx_rate' => DecimalString::class,
            'quoted_on' => 'date:Y-m-d',
            'confidence' => Confidence::class,
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
