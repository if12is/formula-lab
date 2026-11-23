<?php

namespace App\Actions\Assistant;

use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\Assistant\AssistantDisclosureOutcome;
use App\Models\AssistantDisclosure;
use App\Support\Clock\Clock;

/** Appends one line to the disclosure log: which surface, which categories, sent or refused. Never the content. */
final class RecordAssistantDisclosureAction
{
    public function __construct(private readonly Clock $clock) {}

    /** @param  list<AssistantDataCategory>  $categories */
    public function __invoke(string $sessionKey, string $surface, array $categories, AssistantDisclosureOutcome $outcome, ?int $userId = null): AssistantDisclosure
    {
        return AssistantDisclosure::query()->create([
            'session_key' => $sessionKey,
            'surface' => $surface,
            'categories' => array_column($categories, 'value'),
            'outcome' => $outcome,
            'user_id' => $userId,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
