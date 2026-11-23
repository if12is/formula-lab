<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Production\BatchLineKind;
use Database\Factories\ProductionBatchLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient or packaging item of a batch: planned, to weigh, actually weighed, the lot used and what it cost (Q-02, Q-03, Q-09). */
class ProductionBatchLine extends Model
{
    /** @use HasFactory<ProductionBatchLineFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'production_batch_id', 'line_no', 'kind', 'trade_material_id', 'phase', 'uom', 'planned_quantity', 'weigh_quantity', 'actual_quantity',
        'lot_number', 'unit_cost', 'cost_uom', 'unit_cost_source', 'sanad_purchase_line_uuid', 'weighed_by', 'weighed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => BatchLineKind::class,
            'line_no' => 'integer',
            'planned_quantity' => DecimalString::class,
            'weigh_quantity' => DecimalString::class,
            'actual_quantity' => DecimalString::class,
            'unit_cost' => DecimalString::class,
            'weighed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    /** @return BelongsTo<TradeMaterial, $this> */
    public function tradeMaterial(): BelongsTo
    {
        return $this->belongsTo(TradeMaterial::class);
    }
}
