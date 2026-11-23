<?php

namespace App\Models;

use App\Enums\Coa\CoaExtractionStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\CoaExtractionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A certificate of analysis as the model read it (P-05): the stored file with its hash, and what the model proposed. Append-only: the status is
 * the latest event, never a column, so a decision can be neither edited nor undone.
 */
class CoaExtraction extends Model
{
    /** @use HasFactory<CoaExtractionFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'trade_material_id', 'uploaded_by', 'file_path', 'file_name', 'file_mime', 'file_size', 'file_sha256',
        'supplier', 'lot_no', 'document_date', 'text_check', 'model', 'input_tokens', 'output_tokens', 'raw_answer', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('coa_extractions'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('coa_extractions'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d',
            'raw_answer' => 'array',
            'created_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class, 'trade_material_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<CoaExtractionField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(CoaExtractionField::class)->orderBy('position');
    }

    /** @return HasMany<CoaExtractionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CoaExtractionEvent::class)->orderBy('id');
    }

    /** The latest event: where the extraction stands. */
    public function currentEvent(): ?CoaExtractionEvent
    {
        return $this->events()->reorder('id', 'desc')->first();
    }

    public function status(): CoaExtractionStatus
    {
        return $this->currentEvent()?->status ?? CoaExtractionStatus::Extracted;
    }
}
