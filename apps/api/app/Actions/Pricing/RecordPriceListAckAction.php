<?php

namespace App\Actions\Pricing;

use App\Enums\PriceListSyncStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\PriceList;
use App\Models\SanadOutbox;

/**
 * Carries Sanad's verdict on a `price_list_upsert` item (contract 3.2 / 8.6) over to the price list it was about:
 *   applied  -> the list is applied, with Sanad's counts;
 *   failed   -> the list is failed with Sanad's error code and message; a re-queued copy (new outbox id) becomes the row to watch;
 *   conflict -> the list is in conflict (a person edited it in Sanad after the item was produced; not re-queued).
 * An ack for a row that is no longer the list's current publication (a newer publish replaced it) changes nothing.
 */
final class RecordPriceListAckAction
{
    public function __invoke(SanadOutbox $acked, ?SanadOutbox $requeued = null): void
    {
        if ($acked->kind !== SanadOutboxKind::PriceListUpsert || $acked->external_id === null) {
            return;
        }

        $list = PriceList::query()
            ->whereKey($acked->external_id)
            ->where('tenant_id', $acked->device->tenant_id)
            ->lockForUpdate()
            ->first();

        if ($list === null || $list->sync_outbox_id !== $acked->id) {
            return;
        }

        $status = match ($acked->status) {
            SanadOutboxStatus::Applied => PriceListSyncStatus::Applied,
            SanadOutboxStatus::Failed => PriceListSyncStatus::Failed,
            SanadOutboxStatus::Conflict => PriceListSyncStatus::Conflict,
            SanadOutboxStatus::Pending => PriceListSyncStatus::Pending,
        };

        $detail = match ($acked->status) {
            SanadOutboxStatus::Failed => ['error' => $acked->last_error, 'failures' => $acked->failures, 'requeued' => $requeued !== null],
            default => $acked->result,
        };

        $list->forceFill([
            'sync_status' => $status,
            'sync_detail' => $detail,
            'sync_outbox_id' => $requeued?->id ?? $list->sync_outbox_id,
        ])->save();
    }
}
