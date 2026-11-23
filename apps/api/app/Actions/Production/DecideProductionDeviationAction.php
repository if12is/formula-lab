<?php

namespace App\Actions\Production;

use App\Enums\Production\DeviationApprovalStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionDeviation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Approves or rejects a pending deviation. The decision is final (database trigger): who, when and the note stay on the record. */
final class DecideProductionDeviationAction
{
    /** @throws ProductionException When the deviation was already decided or the batch is closed. */
    public function __invoke(ProductionDeviation $deviation, bool $approve, string $note, User $user): ProductionDeviation
    {
        return DB::transaction(function () use ($deviation, $approve, $note, $user): ProductionDeviation {
            $locked = ProductionDeviation::query()->whereKey($deviation->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ProductionException::deviationDecided();
            }

            $locked->forceFill([
                'approval_status' => $approve ? DeviationApprovalStatus::Approved : DeviationApprovalStatus::Rejected,
                'decided_by' => $user->getKey(),
                'decided_by_name' => $user->name,
                'decided_at' => now(),
                'decision_note' => trim($note),
            ])->save();

            return $locked;
        });
    }
}
