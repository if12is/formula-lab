<?php

namespace App\Actions\Decisions;

use App\Models\DecisionOverride;

/** Writes one override row; the row id is the e-signature reference already inside the engine's record. */
final class RecordDecisionOverrideAction
{
    public const SIGNATURE_METHOD = 'password_reentry';

    /**
     * @param  array<string, mixed>  $record  The engine's hashed decision record, carrying the override block.
     */
    public function __invoke(string $signatureId, string $tenantId, string $kind, string $subjectRef, array $record, int $userId, string $role, string $reason): DecisionOverride
    {
        return DecisionOverride::query()->create([
            'id' => $signatureId,
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'subject_ref' => $subjectRef,
            'decision_hash' => $record['decisionHash'],
            'record' => $record,
            'signed_by' => $userId,
            'role' => $role,
            'reason' => $reason,
            'signature_method' => self::SIGNATURE_METHOD,
            'signed_at' => $record['at'],
        ]);
    }
}
