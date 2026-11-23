<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Enums\Assistant\AssistantConsentEventType;
use App\Models\AssistantConsentEvent;
use App\Support\Clock\Clock;

/** Writes the closing row of every grant that outlived the allowed time and has no newer row, so the log shows the end of each consent. Returns how many it closed. */
final class ExpireAssistantConsentsAction
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecordAssistantConsentEventAction $recordEvent,
    ) {}

    public function __invoke(): int
    {
        $cutoff = $this->clock->now()->subMinutes((int) config('assistant.consent.ttl_minutes'));

        $stale = AssistantConsentEvent::query()
            ->where('event', AssistantConsentEventType::Granted->value)
            ->where('occurred_at', '<=', $cutoff)
            ->whereNotExists(fn ($newer) => $newer->from('assistant_consent_events as later')
                ->whereColumn('later.session_key', 'assistant_consent_events.session_key')
                ->whereColumn('later.occurred_at', '>', 'assistant_consent_events.occurred_at'))
            ->get();

        foreach ($stale as $grant) {
            ($this->recordEvent)($grant->session_key, AssistantConsentEventType::Revoked, [], AssistantConsentEndReason::Expired, $grant->user_id);
        }

        return $stale->count();
    }
}
