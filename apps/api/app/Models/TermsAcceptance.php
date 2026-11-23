<?php

namespace App\Models;

use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\TermsAcceptanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded acceptance of one version (and wording hash) of the terms of use. Append-only. */
class TermsAcceptance extends Model
{
    /** @use HasFactory<TermsAcceptanceFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'session_key', 'terms_version', 'terms_hash', 'accepted_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('terms_acceptances'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('terms_acceptances'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
