<?php

namespace App\Models;

use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\FormulaPublicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One push of a finished good to Sanad: the details sent, the hashed decision that allowed it and where it went. Append-only. */
class FormulaPublication extends Model
{
    /** @use HasFactory<FormulaPublicationFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'formula_id', 'cost_approval_id', 'sanad_product_uuid', 'mode', 'outbox_id', 'details', 'decision', 'decision_hash', 'decision_override_id', 'published_by', 'published_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('formula_publications'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('formula_publications'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'decision' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<FormulaCostApproval, $this> */
    public function costApproval(): BelongsTo
    {
        return $this->belongsTo(FormulaCostApproval::class, 'cost_approval_id');
    }
}
