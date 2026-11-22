<?php

namespace App\Services\Decisions;

use App\Actions\Decisions\RecordDecisionOverrideAction;
use App\Actions\Decisions\VerifyElectronicSignatureAction;
use App\Exceptions\Domain\DecisionOverrideException;
use App\Models\DecisionOverride;
use App\Models\User;

/**
 * Y-04: the single write path of decision_overrides. Overriding a blocked decision needs a signed-in user whose role is allowed,
 * a written reason and the e-signature (the password typed again). Deciding WHAT is blocked stays with the caller's engine run;
 * this class only checks the person, signs and records.
 */
final class DecisionOverrideService
{
    public function __construct(
        private readonly OverrideAuthorizer $authorizer,
        private readonly VerifyElectronicSignatureAction $verifySignature,
        private readonly RecordDecisionOverrideAction $record,
    ) {}

    /**
     * Checks the person and their password. Returns what the engine record needs: the signer's role and the signature id.
     *
     * @return array{by: string, role: string, signatureRef: string}
     *
     * @throws DecisionOverrideException When nobody is signed in, the role may not override, or the password is wrong.
     */
    public function sign(?User $user, string $password): array
    {
        if ($user === null) {
            throw DecisionOverrideException::notSignedIn();
        }
        if (! $this->authorizer->allows($user)) {
            throw DecisionOverrideException::roleNotAllowed();
        }

        return [
            'by' => (string) $user->getKey(),
            'role' => (string) $this->authorizer->roleOf($user),
            'signatureRef' => ($this->verifySignature)($user, $password),
        ];
    }

    /**
     * @param  array{by: string, role: string, signatureRef: string}  $signature  From sign().
     * @param  array<string, mixed>  $record  The engine's decision record built with that override.
     */
    public function record(string $tenantId, string $kind, string $subjectRef, array $signature, string $reason, array $record): DecisionOverride
    {
        return ($this->record)($signature['signatureRef'], $tenantId, $kind, $subjectRef, $record, (int) $signature['by'], $signature['role'], $reason);
    }

    /** The latest override of exactly this decision (same inputs, rules and result), if any. */
    public function activeFor(string $kind, string $subjectRef, string $decisionHash): ?DecisionOverride
    {
        return DecisionOverride::query()
            ->where('kind', $kind)->where('subject_ref', $subjectRef)->where('decision_hash', $decisionHash)
            ->orderByDesc('signed_at')->first();
    }

    /** @return array{available: bool, why: string|null} */
    public function availability(?User $user): array
    {
        return $this->authorizer->availability($user);
    }
}
