<?php

namespace App\Models;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\FormulaVersionTransitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One signed workflow move of a formula version: who, when (database clock), comment and the version hash. Append-only. */
class FormulaVersionTransition extends Model
{
    /** @use HasFactory<FormulaVersionTransitionFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['formula_version_id', 'from_status', 'to_status', 'signed_by', 'comment', 'version_hash', 'basis'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('formula_version_transitions'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('formula_version_transitions'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_status' => FormulaStatus::class, 'to_status' => FormulaStatus::class, 'basis' => 'array', 'signed_at' => 'datetime'];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
