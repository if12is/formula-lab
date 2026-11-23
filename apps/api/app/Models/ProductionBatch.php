<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Production\ProductionBatchStatus;
use Database\Factories\ProductionBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A batch made from a production order: a snapshot of the approved version, with what was weighed, which lots and what deviated (Q-11). */
class ProductionBatch extends Model
{
    /** @use HasFactory<ProductionBatchFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'production_order_id', 'batch_number', 'status', 'formula_version_id', 'formula_hash', 'snapshot', 'operator', 'started_at',
        'completed_at', 'completed_by', 'planned_waste_pct', 'actual_waste_g', 'actual_units', 'actual_conversion_egp', 'conversion_note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProductionBatchStatus::class,
            'snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'planned_waste_pct' => DecimalString::class,
            'actual_waste_g' => DecimalString::class,
            'actual_units' => DecimalString::class,
            'actual_conversion_egp' => DecimalString::class,
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return HasMany<ProductionBatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionBatchLine::class)->orderBy('line_no');
    }

    /** @return HasMany<ProductionBatchCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(ProductionBatchCheck::class)->orderBy('id');
    }

    /** @return HasMany<ProductionDeviation, $this> */
    public function deviations(): HasMany
    {
        return $this->hasMany(ProductionDeviation::class)->orderBy('recorded_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === ProductionBatchStatus::InProgress;
    }
}
