<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Confidence;
use App\Enums\TestedValueKind;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\TestedValueMarkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This dose / required HLB of this material was tested on this formula version" (O-07): a local lab provenance record (L1)
 * that points at the stability result behind it. It is never a reference value and never feeds a calculation. Append-only.
 */
class TestedValueMark extends Model
{
    /** @use HasFactory<TestedValueMarkFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'formula_version_id', 'lab_trial_id', 'lab_measurement_id', 'trade_material_id', 'kind', 'value', 'source_id', 'confidence', 'tested_on',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('tested_value_marks'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('tested_value_marks'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TestedValueKind::class,
            'value' => DecimalString::class,
            'confidence' => Confidence::class,
            'tested_on' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<LabTrial, $this> */
    public function trial(): BelongsTo
    {
        return $this->belongsTo(LabTrial::class, 'lab_trial_id');
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class, 'trade_material_id');
    }
}
