<?php

namespace App\Models;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Enums\Assistant\AssistantConsentEventType;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\AssistantConsentEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One step of a session's consent to send data to the model provider (granted or revoked). Append-only; the newest row of a session is its state. */
class AssistantConsentEvent extends Model
{
    /** @use HasFactory<AssistantConsentEventFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['session_key', 'event', 'categories', 'end_reason', 'user_id', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('assistant_consent_events'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('assistant_consent_events'));
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'event' => AssistantConsentEventType::class,
            'end_reason' => AssistantConsentEndReason::class,
            'categories' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
