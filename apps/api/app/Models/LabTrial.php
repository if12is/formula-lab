<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\LabTrialStatus;
use Database\Factories\LabTrialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One batch made in the lab from one formula version, with the measurements taken on it. */
class LabTrial extends Model
{
    /** @use HasFactory<LabTrialFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'formula_version_id', 'code', 'status', 'batch_size', 'batch_unit', 'trial_date', 'operator', 'notes',
        'completed_at', 'completed_by', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LabTrialStatus::class,
            'batch_size' => DecimalString::class,
            'trial_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return HasMany<LabMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(LabMeasurement::class)->orderBy('id');
    }

    public function isCompleted(): bool
    {
        return $this->status === LabTrialStatus::Completed;
    }
}
