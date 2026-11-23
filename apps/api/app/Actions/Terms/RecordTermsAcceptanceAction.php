<?php

namespace App\Actions\Terms;

use App\Models\TermsAcceptance;
use App\Models\User;
use App\Support\Clock\Clock;

/** Writes one acceptance row: the version and wording hash that were shown, who accepted (the session key until accounts exist) and when (the injected clock). */
final class RecordTermsAcceptanceAction
{
    public function __construct(private readonly Clock $clock) {}

    public function __invoke(?User $user, string $sessionKey, string $version, string $hash): TermsAcceptance
    {
        return TermsAcceptance::query()->create([
            'user_id' => $user?->getKey(),
            'session_key' => $sessionKey,
            'terms_version' => $version,
            'terms_hash' => $hash,
            'accepted_at' => $this->clock->now(),
        ]);
    }
}
