<?php

namespace App\Actions\Production;

use App\Actions\Sanad\EnqueueSanadMessageAction;
use App\Enums\Production\ProductionOrderStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;

/**
 * Sends an approved production order to Sanad: queued in the outbox for the Sanad-initiated link (contract 3.1), or, with no device and a
 * LAN address, asked directly (4.3 and 4.4). Sanad consumes the materials and receives the finished good: there is no stock logic here.
 * A queued message is immutable (the outbox refuses a different body under the same external id).
 */
final class SendProductionOrderToSanadAction
{
    public function __construct(
        private readonly BuildProductionOrderPayloadAction $buildPayload,
        private readonly EnqueueSanadMessageAction $enqueue,
        private readonly RunProductionOrderOnSanadAction $runDirect,
    ) {}

    /** @throws ProductionException When the order is not approved, something the message needs is missing, or (LAN) Sanad refuses a step. */
    public function __invoke(ProductionOrder $order): ProductionOrder
    {
        $order = ProductionOrder::query()->findOrFail($order->getKey());
        if ($order->status === ProductionOrderStatus::Approved) {
            $built = ($this->buildPayload)($order);
            if ($built['payload'] !== null && $built['device'] === null) {
                return ($this->runDirect)($order, $built['payload']);
            }
        }

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
