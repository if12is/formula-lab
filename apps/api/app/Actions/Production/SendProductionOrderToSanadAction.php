<?php

namespace App\Actions\Production;

use App\Actions\Sanad\EnqueueSanadMessageAction;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;

/**
 * Queues an approved production order for Sanad (the outbox, contract 3.1). Sanad consumes the materials and receives the finished good:
 * there is no stock logic here. The message is immutable once queued (the outbox refuses a different body under the same external id).
 */
final class SendProductionOrderToSanadAction
{
    public function __construct(
        private readonly BuildProductionOrderPayloadAction $buildPayload,
        private readonly EnqueueSanadMessageAction $enqueue,
    ) {}

    /** @throws ProductionException When the order is not approved or something the message needs is missing. */
    public function __invoke(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order): ProductionOrder {
            $locked = ProductionOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrderStatus::Approved) {
                throw ProductionException::orderNotApproved($locked->code);
            }

            $built = ($this->buildPayload)($locked);
            if ($built['payload'] === null || $built['device'] === null) {
                throw ProductionException::sendBlocked($locked->code, $built['blockers']);
            }

            $row = ($this->enqueue)($built['device'], SanadOutboxKind::ProductionOrder, $built['payload']);
            $locked->forceFill(['status' => ProductionOrderStatus::Sent, 'sent_at' => now(), 'sanad_outbox_id' => $row->id])->save();

            return $locked;
        });
    }
}
