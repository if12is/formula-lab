<?php

namespace App\Services\Production;

use App\Actions\Production\BuildProductionOrderPayloadAction;
use App\Enums\Production\ProductionOrderStatus;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\Costing\CostApprovalService;
use App\Support\DecimalNormalizer;
use App\Support\Sanad\SanadDelivery;

/**
 * Everything the production order page shows. A weights-only role (the operator) gets the order, the sheet and the batch, never a price,
 * a cost or the Sanad money fields: those keys are left out of the props, not hidden by the screen.
 */
final class ProductionOrderDetailService
{
    public function __construct(
        private readonly ProductionSheetService $sheets,
        private readonly BuildProductionOrderPayloadAction $payload,
        private readonly CostApprovalService $approvals,
        private readonly ProductionAudience $audience,
    ) {}

    /** @return array<string, mixed> */
    public function forOrder(ProductionOrder $order, ?User $user): array
    {
        $order->loadMissing(['formulaVersion.formula', 'batch', 'outbox']);
        $outbox = $order->latestOutbox();
        $formula = $order->formulaVersion->formula;
        $seesPrices = $this->audience->seesPrices($user);
        $approver = User::query()->whereKey($order->approved_by)->value('name');

        $props = [
            'order' => [
                'id' => $order->getKey(),
                'code' => $order->code,
                'batch_number' => $order->batch_number,
                'status' => $order->status->value,
                'batch_size' => $order->batch_size,
                'batch_unit' => $order->batch_unit,
                'scale_resolution_g' => DecimalNormalizer::normalize($order->scale_resolution_g),
                'units_count' => $order->units_count === null ? null : DecimalNormalizer::normalize($order->units_count),
                'planned_on' => $order->planned_on?->toDateString(),
                'expiry_date' => $order->expiry_date?->toDateString(),
                'branch_code' => $order->branch_code,
                'notes' => $order->notes,
                'formula_hash' => $order->formula_hash,
                'approved_at' => $order->approved_at?->toIso8601String(),
                'approved_by' => $approver,
                'sent_at' => $order->sent_at?->toIso8601String(),
            ],
            'formula' => [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'version' => $order->formulaVersion->version,
                'editorHref' => route('formulas.versions.edit', [$formula->getKey(), $order->formulaVersion->version], false),
            ],
            'snapshot' => $order->snapshot,
            'sheet' => $this->sheets->forOrder($order),
            'batch' => $order->batch === null ? null : [
                'id' => $order->batch->getKey(),
                'number' => $order->batch->batch_number,
                'status' => $order->batch->status->value,
                'href' => route('production.batches.show', $order->batch, false),
            ],
            'approval' => $this->audience->approvalAvailability($user),
            'audience' => ['sees_prices' => $seesPrices],
            'choices' => ['wasteStep' => null],
            'urls' => [
                'index' => route('production.orders.index', [], false),
                'approve' => route('production.orders.approve', $order, false),
                'send' => route('production.orders.send', $order, false),
                'startBatch' => route('production.orders.batch.store', $order, false),
                'weighing' => route('production.orders.weighing', $order, false),
            ],
        ];

        $props['can'] = [
            'approve' => $order->status === ProductionOrderStatus::Draft,
            'send' => $order->status === ProductionOrderStatus::Approved,
            'startBatch' => $order->batch === null && in_array($order->status, [ProductionOrderStatus::Approved, ProductionOrderStatus::Sent], true),
        ];

        if (! $seesPrices) {
            return $props;
        }

        $built = $order->status === ProductionOrderStatus::Draft ? null : ($this->payload)($order);
        $approval = $this->approvals->current($formula);
        $props['cost'] = $approval === null ? null : ['unit_cost' => DecimalNormalizer::normalize($approval->unit_cost), 'approved_at' => $approval->approved_at->toIso8601String()];
        // Where the stock effect stands in Sanad: from its answer to the queued message, or to the direct (LAN) steps.
        $delivery = $order->sanad_sync === null ? SanadDelivery::fromOutbox($outbox, confirmsStock: true) : SanadDelivery::fromSync($order->sanad_sync);
        $props['sanad'] = [
            'mode' => $order->sanad_sync !== null ? 'lan' : ($outbox !== null ? 'outbox' : null),
            'delivery' => $delivery->toArray(),
            'sync' => $order->sanad_sync,
            'blockers' => $built['blockers'] ?? [],
            'payload' => $built['payload'] ?? null,
            'outbox' => $outbox === null ? null : [
                'id' => $outbox->id,
                'status' => $outbox->status->value,
                'failures' => $outbox->failures,
                'result' => $outbox->result,
                'error' => $outbox->last_error,
                'acked_at' => $outbox->acked_at?->toIso8601String(),
            ],
        ];

        return $props;
    }
}
