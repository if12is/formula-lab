<?php

namespace App\Models;

use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\DecisionOverrideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed, reasoned decision to go ahead although the rules blocked (Y-04): who, in which role, why, when, and the engine's
 * hashed record (which still lists every failed rule). Append-only (also enforced by a PostgreSQL trigger).
 */
class DecisionOverride extends Model
{
    /** @use HasFactory<DecisionOverrideFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['id', 'tenant_id', 'kind', 'subject_ref', 'decision_hash', 'record', 'signed_by', 'role', 'reason', 'signature_method', 'signed_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('decision_overrides'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('decision_overrides'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['record' => 'array', 'signed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
