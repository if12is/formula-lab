<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationKind;
use App\Enums\Production\DeviationVerdict;
use Database\Factories\ProductionDeviationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A departure from the plan: why, who and when, the engine's verdict, and the approval it waits for when above the threshold (Q-11). */
class ProductionDeviation extends Model
{
    /** @use HasFactory<ProductionDeviationFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'production_batch_id', 'kind', 'subject', 'planned', 'actual', 'unit', 'deviation_pct', 'threshold_pct', 'verdict', 'requires_approval',
        'approval_status', 'reason', 'recorded_by_name', 'recorded_by', 'recorded_at', 'decided_by_name', 'decided_by', 'decided_at', 'decision_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DeviationKind::class,
            'verdict' => DeviationVerdict::class,
            'approval_status' => DeviationApprovalStatus::class,
            'requires_approval' => 'boolean',
            'planned' => DecimalString::class,
            'actual' => DecimalString::class,
            'deviation_pct' => DecimalString::class,
            'threshold_pct' => DecimalString::class,
            'recorded_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function isPending(): bool
    {
        return $this->approval_status === DeviationApprovalStatus::Pending;
    }
}
