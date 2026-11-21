<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\LabMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sourced measured value of a trial (value, unit, method, instrument, time, confidence). Append-only, also by trigger. */
class LabMeasurement extends Model
{
    /** @use HasFactory<LabMeasurementFactory> */
    use Auditable, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lab_trial_id', 'property', 'value', 'unit', 'lab_protocol_id', 'method', 'instrument', 'measured_at', 'confidence', 'note', 'recorded_by',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('lab_measurements'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('lab_measurements'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'property' => LabProperty::class,
            'value' => DecimalString::class,
            'confidence' => Confidence::class,
            'measured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LabTrial, $this> */
    public function trial(): BelongsTo
    {
        return $this->belongsTo(LabTrial::class, 'lab_trial_id');
    }

    /** @return BelongsTo<LabProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(LabProtocol::class, 'lab_protocol_id');
    }
}
