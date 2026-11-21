<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dated, sourced exchange rate: `rate` units of the base currency (EGP) per 1 unit of `from_currency`. */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'from_currency', 'to_currency', 'rate', 'rate_date', 'source_id', 'confidence', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate' => DecimalString::class,
            'rate_date' => 'date:Y-m-d',
            'confidence' => Confidence::class,
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
