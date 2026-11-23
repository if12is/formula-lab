<?php

namespace App\Actions\Production;

use App\Enums\Production\ProductionOrderStatus;
use App\Exceptions\Domain\ProductionException;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Approves a draft production order: from then on only its sending can change (database trigger). */
final class ApproveProductionOrderAction
{
    /** @throws ProductionException When the order is not a draft. */
    public function __invoke(ProductionOrder $order, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($order, $user): ProductionOrder {
            $locked = ProductionOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== ProductionOrderStatus::Draft) {
                throw ProductionException::orderNotDraft($locked->code);
            }

            $locked->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_by' => $user->getKey(), 'approved_at' => now()])->save();

            return $locked;
        });
    }
}
