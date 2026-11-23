<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantConsentEventType;
use App\Enums\Assistant\AssistantDataCategory;
use App\Models\AssistantConsentEvent;
use App\Support\Clock\Clock;
use App\Support\DTOs\AssistantConsentState;

/** Reads a session's consent: the newest row decides, and a grant older than the allowed time no longer counts (the clock closes it). */
final class ResolveAssistantConsentAction
{
    public function __construct(private readonly Clock $clock) {}

    public function __invoke(?string $sessionKey): AssistantConsentState
    {
        if ($sessionKey === null || $sessionKey === '') {
            return AssistantConsentState::none();
        }

        $latest = AssistantConsentEvent::query()->where('session_key', $sessionKey)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        if ($latest === null || $latest->event !== AssistantConsentEventType::Granted) {
            return AssistantConsentState::none();
        }

        $grantedAt = $latest->occurred_at->toImmutable();
        $expiresAt = $grantedAt->addMinutes((int) config('assistant.consent.ttl_minutes'));
        if (! $this->clock->now()->lessThan($expiresAt)) {
            return AssistantConsentState::none();
        }

        return new AssistantConsentState(
            array_values(array_filter(array_map(fn (string $value): ?AssistantDataCategory => AssistantDataCategory::tryFrom($value), (array) $latest->categories))),
            $grantedAt,
            $expiresAt,
        );
    }
}
