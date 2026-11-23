<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Coa\CoaReadingConfidence;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\CoaExtractionFieldFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One value the model proposed from a COA, after the deterministic checks. Append-only; it is an unconfirmed draft (L7) until a person confirms it. */
class CoaExtractionField extends Model
{
    /** @use HasFactory<CoaExtractionFieldFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'coa_extraction_id', 'position', 'property_as_printed', 'property_key', 'value', 'min', 'max', 'unit', 'as_printed',
        'converted_from', 'method', 'quote', 'page', 'reading_confidence', 'issues',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('coa_extraction_fields'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('coa_extraction_fields'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => DecimalString::class,
            'min' => DecimalString::class,
            'max' => DecimalString::class,
            'as_printed' => 'array',
            'issues' => 'array',
            'reading_confidence' => CoaReadingConfidence::class,
        ];
    }

    /** @return BelongsTo<CoaExtraction, $this> */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(CoaExtraction::class, 'coa_extraction_id');
    }
}
