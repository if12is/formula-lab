<?php

namespace App\Actions\Production;

use App\Enums\Production\ProductionOrderStatus;
use App\Exceptions\Domain\ProductionException;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Integrations\Sanad\SanadClient;
use App\Models\ProductionOrder;

/**
 * LAN direct mode of an approved production order (contract 4.3 and 4.4): Sanad consumes the materials and packaging, then receives
 * the finished good with the approved unit cost. Both calls carry a key made from their body, and Sanad also keys them by the order
 * code, so sending the same order again is a no-op there. What Sanad answers for each step is kept on the order (`sanad_sync`); when a
 * step is refused the order stays approved with the refusal on it and the same send can be made again: a step Sanad already did is
 * not asked twice. There is no stock logic here.
 */
final class RunProductionOrderOnSanadAction
{
    public function __construct(private readonly SanadClient $client) {}

    /**
     * @param  array<string, mixed>  $payload  The order's `production_order` message (BuildProductionOrderPayloadAction).
     *
     * @throws ProductionException When Sanad refuses or cannot be reached; the refusal is recorded on the order first.
     */
    public function __invoke(ProductionOrder $order, array $payload): ProductionOrder
    {
        $sync = $order->sanad_sync ?? [];
        $sync = [...$sync, 'mode' => 'lan', 'attempts' => ($sync['attempts'] ?? 0) + 1, 'last_attempt_at' => now()->toIso8601String(), 'error' => null];

        foreach (['consume' => fn () => $this->consume($order, $payload), 'receive' => fn () => $this->receive($order, $payload)] as $step => $run) {
            if (($sync[$step]['status'] ?? null) === ($step === 'consume' ? 'consumed' : 'received')) {
                continue;
            }

            try {
                $sync[$step] = $run();
            } catch (SanadIntegrationException $exception) {
                $sync['error'] = [
                    'step' => $step,
                    'message' => $exception->getMessage(),
                    'remote_message' => $exception->remoteMessage,
                    'status' => $exception->status,
                    'shortfalls' => $exception->shortfalls(),
                ];
                $order->forceFill(['sanad_sync' => $sync])->save();

                throw ProductionException::sanadStepRefused($order->code, $step, $exception->remoteMessage ?? $exception->getMessage());
            }
        }

        $order->forceFill(['sanad_sync' => $sync, 'status' => ProductionOrderStatus::Sent, 'sent_at' => now()])->save();

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    private function consume(ProductionOrder $order, array $payload): array
    {
        $lines = array_map(fn (array $line): array => ['product_uuid' => $line['product_uuid'], 'quantity' => $line['quantity'], 'uom' => $line['uom']], $payload['consume']);
        $answer = $this->client->consume($order->code, $lines, ['branch_code' => $payload['branch_code'], 'consumed_on' => $payload['production_date']]);

        return ['status' => $answer['status'] ?? null, 'total_cost' => $answer['total_cost'] ?? null, 'at' => now()->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SanadIntegrationException
     */
    private function receive(ProductionOrder $order, array $payload): array
    {
        $answer = $this->client->receive($order->code, [
            'branch_code' => $payload['branch_code'],
            'finished_good_uuid' => $payload['finished_good_uuid'],
            'batch_number' => $payload['batch_number'],
            'quantity' => $payload['receive']['quantity'],
            'uom' => $payload['receive']['uom'],
            'unit_cost' => $payload['receive']['unit_cost'],
            'production_date' => $payload['production_date'],
            'expiry_date' => $payload['expiry_date'],
        ]);

        return [
            'status' => $answer['status'] ?? null,
            'product_batch_uuid' => $answer['product_batch_uuid'] ?? null,
            'stock_movement_uuid' => $answer['stock_movement_uuid'] ?? null,
            'at' => now()->toIso8601String(),
        ];
    }
}
