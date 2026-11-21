<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadRefType;
use App\Models\SanadRef;
use App\Support\DTOs\SanadChangeOutcome;
use Carbon\CarbonImmutable;

/**
 * The single writer of sanad_refs for pushed changes. Sanad wins, except that an update older than
 * the stored one (out-of-order delivery) is refused as a `formula_lab_newer` conflict.
 */
final class UpsertSanadRefAction
{
    public function __invoke(
        string $tenantId,
        SanadRefType $type,
        string $sanadUuid,
        CarbonImmutable $updatedAt,
        string $payloadHash,
        bool $deleted,
    ): SanadChangeOutcome {
        $ref = SanadRef::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $type)
            ->where('sanad_uuid', $sanadUuid)
            ->lockForUpdate()
            ->first();

        if ($ref === null) {
            return SanadChangeOutcome::applied(SanadRef::query()->create([
                'tenant_id' => $tenantId,
                'entity_type' => $type,
                'sanad_uuid' => $sanadUuid,
                'last_synced_at' => now(),
                'payload_hash' => $payloadHash,
                'sanad_updated_at' => $updatedAt,
                'deleted_at' => $deleted ? $updatedAt : null,
            ]));
        }

        if ($ref->sanad_updated_at !== null && $updatedAt->lessThan($ref->sanad_updated_at)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::FormulaLabNewer, $ref->sanad_updated_at);
        }

        $identical = $ref->sanad_updated_at !== null
            && $updatedAt->equalTo($ref->sanad_updated_at)
            && $ref->payload_hash === $payloadHash
            && ($ref->deleted_at !== null) === $deleted;
        if ($identical) {
            return SanadChangeOutcome::unchanged($ref);
        }

        $ref->fill([
            'last_synced_at' => now(),
            'payload_hash' => $payloadHash,
            'sanad_updated_at' => $updatedAt,
            'deleted_at' => $deleted ? $updatedAt : null,
        ])->save();

        return SanadChangeOutcome::applied($ref);
    }
}
