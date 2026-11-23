<?php

namespace App\Services\Production;

use App\Actions\Production\ApproveProductionOrderAction;
use App\Actions\Production\CreateProductionOrderAction;
use App\Actions\Production\SendProductionOrderToSanadAction;
use App\Exceptions\Domain\ProductionException;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Models\User;

/** The single write path of production orders (Q-01). Reads live in ProductionOrderListService / ProductionOrderDetailService. */
final class ProductionOrderService
{
    public function __construct(
        private readonly CreateProductionOrderAction $createOrder,
        private readonly ApproveProductionOrderAction $approveOrder,
        private readonly SendProductionOrderToSanadAction $sendOrder,
        private readonly ProductionAudience $audience,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ProductionException
     */
    public function create(FormulaVersion $version, array $data, ?User $user = null): ProductionOrder
    {
        return ($this->createOrder)($version, $data, $user);
    }

    /** @throws ProductionException When the person may not approve, or the order is not a draft. */
    public function approve(ProductionOrder $order, ?User $user): ProductionOrder
    {
        if ($user === null || ! $this->audience->allowsApproval($user)) {
            throw ProductionException::approverRequired();
        }

        return ($this->approveOrder)($order, $user);
    }

    /** @throws ProductionException */
    public function send(ProductionOrder $order): ProductionOrder
    {
        return ($this->sendOrder)($order);
    }
}
