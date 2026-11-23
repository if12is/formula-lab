<?php

namespace App\Support\Sanad;

use App\Enums\Sanad\SanadDeliveryStatus;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\SanadOutbox;

/**
 * What Sanad answered for a message, in the shape the screens show (the status word, the attempts, the last refusal, Sanad's own
 * result). Read only: it never touches a stock figure, Sanad's answer is shown as it came.
 */
final readonly class SanadDelivery
{
    /**
     * @param  array<string, mixed>|null  $result  What Sanad answered (its statuses and ids).
     * @param  array<string, mixed>|null  $error  The last refusal: `{message, code?, ...}`.
     */
    public function __construct(
        public SanadDeliveryStatus $status,
        public int $failures = 0,
        public ?array $result = null,
        public ?array $error = null,
        public ?string $ackedAt = null,
        public ?string $outboxId = null,
    ) {}

    /**
     * The delivery of an outbox row (the newest of its retry chain, see SanadOutbox::latestInChain()).
     *
     * @param  bool  $confirmsStock  A production order: only "consumed" and "received" in Sanad's answer make it confirmed.
     */
    public static function fromOutbox(?SanadOutbox $row, bool $confirmsStock = false): self
    {
        if ($row === null) {
            return new self(SanadDeliveryStatus::NotSent);
        }

        $status = match ($row->status) {
            SanadOutboxStatus::Pending => $row->failures > 0 ? SanadDeliveryStatus::Retrying : SanadDeliveryStatus::Queued,
            SanadOutboxStatus::Failed => $row->failures >= config('sanad.outbox.max_failures') ? SanadDeliveryStatus::NeedsReview : SanadDeliveryStatus::Failed,
            SanadOutboxStatus::Conflict => SanadDeliveryStatus::Conflict,
            SanadOutboxStatus::Applied => $confirmsStock ? self::stockStatus($row->result ?? []) : SanadDeliveryStatus::Applied,
        };

        return new self($status, $row->failures, $row->result, $row->last_error, $row->acked_at?->toIso8601String(), $row->id);
    }

    /**
     * The delivery of a direct (LAN) production order run, from its `sanad_sync` record.
     *
     * @param  array<string, mixed>|null  $sync
     */
    public static function fromSync(?array $sync): self
    {
        if ($sync === null) {
            return new self(SanadDeliveryStatus::NotSent);
        }

        $consumed = ($sync['consume']['status'] ?? null) === 'consumed';
        $error = $sync['error'] ?? null;
        $status = match (true) {
            $error === null => self::stockStatus(['consume_status' => $sync['consume']['status'] ?? null, 'receive_status' => $sync['receive']['status'] ?? null]),
            $consumed => SanadDeliveryStatus::Partial,
            default => SanadDeliveryStatus::Failed,
        };

        return new self($status, (int) ($sync['attempts'] ?? 0), ['consume_status' => $sync['consume']['status'] ?? null, 'receive_status' => $sync['receive']['status'] ?? null, ...array_filter(['product_batch_uuid' => $sync['receive']['product_batch_uuid'] ?? null])], $error, $sync['last_attempt_at'] ?? null);
    }

    /** @param  array<string, mixed>  $result */
    private static function stockStatus(array $result): SanadDeliveryStatus
    {
        $consumed = ($result['consume_status'] ?? null) === 'consumed';
        $received = ($result['receive_status'] ?? null) === 'received';

        return match (true) {
            $consumed && $received => SanadDeliveryStatus::Confirmed,
            $consumed => SanadDeliveryStatus::Partial,
            default => SanadDeliveryStatus::Applied,
        };
    }

    /** @return array{status: string, failures: int, max_failures: int, needs_attention: bool, result: array<string, mixed>|null, error: array<string, mixed>|null, acked_at: string|null, outbox_id: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'failures' => $this->failures,
            'max_failures' => config('sanad.outbox.max_failures'),
            'needs_attention' => $this->status->needsAttention(),
            'result' => $this->result,
            'error' => $this->error,
            'acked_at' => $this->ackedAt,
            'outbox_id' => $this->outboxId,
        ];
    }
}
