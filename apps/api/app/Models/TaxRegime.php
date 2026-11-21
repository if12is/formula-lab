<?php

namespace App\Models;

use Database\Factories\TaxRegimeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated tax rate with its source and confidence (engine TaxRegime). Global until tenants own regimes (U-04). */
class TaxRegime extends Model
{
    /** @use HasFactory<TaxRegimeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['tax_type', 'rate_pct', 'valid_from', 'valid_to', 'source_id', 'confidence', 'verification'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function isPending(): bool
    {
        return $this->verification === 'pending';
    }
}
