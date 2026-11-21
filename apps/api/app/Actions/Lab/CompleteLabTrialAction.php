<?php

namespace App\Actions\Lab;

use App\Enums\LabTrialStatus;
use App\Exceptions\Domain\LabTrialException;
use App\Models\LabTrial;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Closes a trial: from then on its measurements are final (also enforced by a trigger). */
final class CompleteLabTrialAction
{
    /** @throws LabTrialException When the trial is already completed or holds no measurement. */
    public function __invoke(LabTrial $trial, ?User $user = null): LabTrial
    {
        return DB::transaction(function () use ($trial, $user): LabTrial {
            $locked = LabTrial::query()->whereKey($trial->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCompleted()) {
                throw LabTrialException::closed($locked->code);
            }

            if (! $locked->measurements()->exists()) {
                throw LabTrialException::noMeasurements($locked->code);
            }

            $locked->update([
                'status' => LabTrialStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $user?->getKey(),
            ]);

            return $locked;
        });
    }
}
