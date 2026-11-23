<?php

namespace App\Models;

use App\Enums\Coa\CoaExtractionStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\CoaExtractionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a COA extraction: extracted, then confirmed or rejected by a person (who and when). Append-only. */
class CoaExtractionEvent extends Model
{
    /** @use HasFactory<CoaExtractionEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['coa_extraction_id', 'status', 'user_id', 'occurred_at', 'reason', 'decisions', 'property_value_ids'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('coa_extraction_events'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('coa_extraction_events'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CoaExtractionStatus::class,
            'occurred_at' => 'datetime',
            'decisions' => 'array',
            'property_value_ids' => 'array',
        ];
    }

    /** @return BelongsTo<CoaExtraction, $this> */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(CoaExtraction::class, 'coa_extraction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
