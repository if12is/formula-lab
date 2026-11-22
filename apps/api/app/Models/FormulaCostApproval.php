<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Enums\Confidence;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\FormulaCostApprovalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signed approval of a product's unit cost on one approved formula version. Append-only: a new approval is a new row. */
class FormulaCostApproval extends Model
{
    /** @use HasFactory<FormulaCostApprovalFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'formula_id', 'formula_version_id', 'unit_cost', 'unit_cost_source_id', 'unit_cost_confidence', 'material_prices',
        'report_input_hash', 'report_output_hash', 'comment', 'approved_by', 'approved_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('formula_cost_approvals'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('formula_cost_approvals'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_cost' => DecimalString::class,
            'unit_cost_confidence' => Confidence::class,
            'material_prices' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function unitCostSource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'unit_cost_source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
