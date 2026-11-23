<?php

namespace App\Services\Terms;

use App\Actions\Terms\RecordTermsAcceptanceAction;
use App\Exceptions\Domain\TermsException;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Support\Terms\TermsDocument;

/**
 * The single write path of terms acceptances and the check behind the gate (S-16). A person has accepted when a row exists for them
 * (their user, or their browser session until sign-in exists) with the CURRENT version and the CURRENT wording hash.
 */
final class TermsAcceptanceService
{
    public function __construct(private readonly RecordTermsAcceptanceAction $recordAcceptance) {}

    /** The session id is only ever stored hashed. */
    public function sessionKey(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** The newest acceptance of the current version and wording, or null when this person must be asked. */
    public function current(?User $user, string $sessionId): ?TermsAcceptance
    {
        return TermsAcceptance::query()
            ->where('terms_version', TermsDocument::version())
            ->where('terms_hash', TermsDocument::hash())
            ->where(fn ($query) => $query
                ->when($user !== null, fn ($inner) => $inner->where('user_id', $user->getKey())->orWhere('session_key', $this->sessionKey($sessionId)))
                ->when($user === null, fn ($inner) => $inner->where('session_key', $this->sessionKey($sessionId))))
            ->orderByDesc('accepted_at')
            ->first();
    }

    public function hasAccepted(?User $user, string $sessionId): bool
    {
        return $this->current($user, $sessionId) !== null;
    }

    /**
     * @throws TermsException When the version shown on the screen is no longer the current one (the wording changed while the page was open).
     */
    public function accept(?User $user, string $sessionId, string $submittedVersion): TermsAcceptance
    {
        if ($submittedVersion !== TermsDocument::version()) {
            throw TermsException::versionChanged($submittedVersion, TermsDocument::version());
        }

        return ($this->recordAcceptance)($user, $this->sessionKey($sessionId), TermsDocument::version(), TermsDocument::hash());
    }

    /**
     * What the terms screen shows.
     *
     * @return array<string, mixed>
     */
    public function screen(?User $user, string $sessionId): array
    {
        $accepted = $this->current($user, $sessionId);

        return [
            'document' => [
                'version' => TermsDocument::version(),
                'hash' => TermsDocument::hash(),
                'draft' => TermsDocument::isDraft(),
                'sections' => TermsDocument::sections(),
            ],
            'acceptance' => $accepted === null ? null : [
                'accepted_at' => $accepted->accepted_at->toIso8601String(),
                'version' => $accepted->terms_version,
            ],
        ];
    }
}
