<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantConsentEndReason;
use App\Enums\Assistant\AssistantConsentEventType;
use App\Enums\Assistant\AssistantDataCategory;
use App\Models\AssistantConsentEvent;
use App\Support\Clock\Clock;

/** Appends one consent row (a grant or a revoke) stamped by the injected clock. */
final class RecordAssistantConsentEventAction
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  list<AssistantDataCategory>  $categories */
    public function __invoke(string $sessionKey, AssistantConsentEventType $event, array $categories, ?AssistantConsentEndReason $endReason = null, ?int $userId = null): AssistantConsentEvent
    {
        return AssistantConsentEvent::query()->create([
            'session_key' => $sessionKey,
            'event' => $event,
            'categories' => array_column($categories, 'value'),
            'end_reason' => $endReason,
            'user_id' => $userId,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
