<?php

namespace App\Actions\Sanad;

use App\Actions\Pricing\RecordPriceListAckAction;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records Sanad's verdict on one outbox item (contract 3.2). Returns false when the id is unknown,
 * belongs to another device or was already acked (ignored, never an error).
 *
 * A failed item is flagged for human review and re-queued as a fresh row so Sanad's cursor sees it again,
 * until it has failed `sanad.outbox.max_failures` times; then it stays flagged and an alert is logged. A price list item also
 * reports its verdict to the price list it is about (RecordPriceListAckAction).
 */
final class AcknowledgeSanadOutboxItemAction
{
    public function __construct(private readonly RecordPriceListAckAction $recordPriceListAck) {}

    /**
     * @param  array{id: string, status: string, result?: array<string, mixed>|null, error?: array<string, mixed>|null}  $ack
     */
    public function __invoke(SanadDevice $device, array $ack): bool
    {
        return DB::transaction(function () use ($device, $ack): bool {
            $item = SanadOutbox::query()
                ->where('device_id', $device->getKey())
                ->where('id', $ack['id'])
                ->where('status', SanadOutboxStatus::Pending)
                ->lockForUpdate()
                ->first();
            if ($item === null) {
                return false;
            }

            $status = SanadOutboxStatus::from($ack['status']);
            $item->fill([
                'status' => $status,
                'result' => $ack['result'] ?? null,
                'last_error' => $ack['error'] ?? null,
                'acked_at' => now(),
            ]);
            if ($status === SanadOutboxStatus::Failed) {
                $item->failures++;
            }
            $item->save();

            $requeued = $status === SanadOutboxStatus::Failed ? $this->retryOrAlert($item) : null;

            ($this->recordPriceListAck)($item, $requeued);

            return true;
        });
    }

    private function retryOrAlert(SanadOutbox $failed): ?SanadOutbox
    {
        if ($failed->failures >= config('sanad.outbox.max_failures')) {
            Log::alert('Sanad outbox item failed repeatedly and needs human review.', [
                'outbox_id' => $failed->id,
                'kind' => $failed->kind->value,
                'external_id' => $failed->external_id,
                'failures' => $failed->failures,
            ]);

            return null;
        }

        return SanadOutbox::query()->create([
            'id' => config('sanad.outbox.id_prefix').Str::lower((string) Str::ulid()),
            'device_id' => $failed->device_id,
            'kind' => $failed->kind,
            'external_id' => $failed->external_id,
            'payload' => $failed->payload,
            'status' => SanadOutboxStatus::Pending,
            'failures' => $failed->failures,
            'retry_of' => $failed->id,
            'created_at' => now(),
        ]);
    }
}
