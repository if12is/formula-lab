<?php

namespace App\Models;

use App\Casts\DecimalString;
use App\Concerns\Auditable;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadOutboxKind;
use Database\Factories\ProductionOrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** An approved formula version made into an order with a batch size, holding a snapshot of what will be made (Q-01). */
class ProductionOrder extends Model
{
    /** @use HasFactory<ProductionOrderFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'code', 'batch_number', 'formula_version_id', 'formula_hash', 'snapshot', 'batch_size', 'batch_unit', 'scale_resolution_g', 'units_count',
        'planned_on', 'expiry_date', 'branch_code', 'status', 'approved_by', 'approved_at', 'sent_at', 'sanad_outbox_id', 'notes', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProductionOrderStatus::class,
            'snapshot' => 'array',
            'batch_size' => DecimalString::class,
            'scale_resolution_g' => DecimalString::class,
            'units_count' => DecimalString::class,
            'planned_on' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SanadOutbox, $this> */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(SanadOutbox::class, 'sanad_outbox_id');
    }

    /**
     * The newest outbox row of this order's message: a failed ack re-queues the message under a new id (contract 3.2), so the row the
     * order first pointed at is not always the one that says where Sanad stands.
     */
    public function latestOutbox(): ?SanadOutbox
    {
        if ($this->sanad_outbox_id === null) {
            return null;
        }

        return SanadOutbox::query()
            ->where('kind', SanadOutboxKind::ProductionOrder)
            ->where('external_id', $this->code)
            ->where('device_id', $this->outbox()->value('device_id'))
            ->orderByRaw('id collate "C" desc')
            ->first() ?? $this->outbox;
    }

    /** @return HasOne<ProductionBatch, $this> */
    public function batch(): HasOne
    {
        return $this->hasOne(ProductionBatch::class);
    }
}
