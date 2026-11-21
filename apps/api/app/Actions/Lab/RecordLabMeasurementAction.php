<?php

namespace App\Actions\Lab;

use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Exceptions\Domain\LabTrialException;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\User;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Appends one measured value to an open trial, as a local lab result (L1). The value is a plain decimal string and is
 * required: a measurement nobody took is no row, never a zero. A correction is simply a newer row of the same property.
 * A viscosity reading may also state the salt % added when it was taken: it is then a point of the formula's own salt curve (O-03).
 */
final class RecordLabMeasurementAction
{
    /**
     * @param  array{property: string, value: string, unit: string, salt_pct?: string|null, lab_protocol_id?: string|null, method?: string|null, instrument: string, measured_at: string, note?: string|null}  $data
     *
     * @throws LabTrialException When the trial is completed, the value is not a decimal, or the unit does not belong to the property.
     */
    public function __invoke(LabTrial $trial, array $data, ?User $user = null): LabMeasurement
    {
        $property = LabProperty::from($data['property']);

        if (! is_string($data['value']) || ! DecimalNormalizer::isValid($data['value'])) {
            throw LabTrialException::valueNotDecimal($property->label());
        }

        if (! in_array($data['unit'], $property->units(), true)) {
            throw LabTrialException::unitNotAllowed($data['unit'], $property->label());
        }

        $salt = $data['salt_pct'] ?? null;

        if ($salt !== null && $property !== LabProperty::Viscosity) {
            throw LabTrialException::saltOnlyForViscosity();
        }

        if ($salt !== null && (! DecimalNormalizer::isValid($salt) || str_starts_with($salt, '-'))) {
            throw LabTrialException::saltNotDecimal();
        }

        return DB::transaction(function () use ($trial, $data, $property, $salt, $user): LabMeasurement {
            $locked = LabTrial::query()->whereKey($trial->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCompleted()) {
                throw LabTrialException::closed($locked->code);
            }

            return LabMeasurement::query()->create([
                'lab_trial_id' => $locked->getKey(),
                'property' => $property,
                'value' => $data['value'],
                'unit' => $data['unit'],
                'salt_pct' => $salt,
                'lab_protocol_id' => $data['lab_protocol_id'] ?? null,
                'method' => $data['method'] ?? null,
                'instrument' => $data['instrument'],
                'measured_at' => $data['measured_at'],
                'confidence' => Confidence::L1CoaBatch,
                'note' => $data['note'] ?? null,
                'recorded_by' => $user?->getKey(),
            ]);
        });
    }
}
