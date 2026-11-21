<?php

namespace App\Actions\Lab;

use App\Enums\LabTrialStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Opens a trial of one formula version. Its code is the formula code and a running number: "F-0001/T3". */
final class CreateLabTrialAction
{
    /**
     * @param  array{batch_size: string, batch_unit: string, trial_date: string, operator: string, notes?: string|null}  $data
     */
    public function __invoke(FormulaVersion $version, array $data, ?User $user = null): LabTrial
    {
        return DB::transaction(function () use ($version, $data, $user): LabTrial {
            // Serialise concurrent trials of the same formula so the running numbers never collide.
            $formula = Formula::query()->whereKey($version->formula_id)->lockForUpdate()->firstOrFail();

            $count = LabTrial::query()
                ->whereIn('formula_version_id', $formula->versions()->reorder()->select('id'))
                ->count();

            return LabTrial::query()->create([
                'tenant_id' => $formula->tenant_id,
                'formula_version_id' => $version->getKey(),
                'code' => $formula->code.'/T'.($count + 1),
                'status' => LabTrialStatus::InProgress,
                'batch_size' => $data['batch_size'],
                'batch_unit' => $data['batch_unit'],
                'trial_date' => $data['trial_date'],
                'operator' => $data['operator'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->getKey(),
            ]);
        });
    }
}
