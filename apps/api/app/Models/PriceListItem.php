<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use App\Enums\PriceListMode;
use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product on a price list: priced by a config rule, or by an explicit override that carries its source and confidence. */
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'price_list_id', 'formula_id', 'mode', 'rule_key', 'override_price', 'override_source_id', 'override_confidence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => PriceListMode::class,
            'override_price' => DecimalString::class,
            'override_confidence' => Confidence::class,
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function overrideSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'override_source_id');
    }
}
