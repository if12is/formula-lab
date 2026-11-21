<?php

namespace App\Services\Lab;

use App\Actions\Lab\CompleteLabTrialAction;
use App\Actions\Lab\CreateLabTrialAction;
use App\Actions\Lab\RecordLabMeasurementAction;
use App\Exceptions\Domain\LabTrialException;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\User;

/** The single write path of lab trials and their measurements. Reads live in LabTrialListService / LabTrialDetailService. */
final class LabTrialService
{
    public function __construct(
        private CreateLabTrialAction $createTrial,
        private RecordLabMeasurementAction $recordMeasurement,
        private CompleteLabTrialAction $completeTrial,
    ) {}

    /**
     * Trials may be opened for any version, an approved one included (the version itself stays untouched).
     *
     * @param  array{batch_size: string, batch_unit: string, trial_date: string, operator: string, notes?: string|null}  $data
     */
    public function start(FormulaVersion $version, array $data, ?User $user = null): LabTrial
    {
        return ($this->createTrial)($version, $data, $user);
    }

    /**
     * @param  array{property: string, value: string, unit: string, lab_protocol_id?: string|null, method?: string|null, instrument: string, measured_at: string, note?: string|null}  $data
     *
     * @throws LabTrialException
     */
    public function record(LabTrial $trial, array $data, ?User $user = null): LabMeasurement
    {
        return ($this->recordMeasurement)($trial, $data, $user);
    }

    /** @throws LabTrialException */
    public function complete(LabTrial $trial, ?User $user = null): LabTrial
    {
        return ($this->completeTrial)($trial, $user);
    }
}
