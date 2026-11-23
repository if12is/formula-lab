<?php

namespace App\Models;

use App\Casts\DecimalString;
use Database\Factories\ProductionBatchCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reading taken at a check point during a batch. Append-only: a correction is a newer reading. */
class ProductionBatchCheck extends Model
{
    /** @use HasFactory<ProductionBatchCheckFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['production_batch_id', 'step_no', 'check_no', 'property', 'value', 'unit', 'status', 'measured_by', 'measured_at', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => DecimalString::class, 'measured_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<ProductionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }
}
