<?php

namespace App\Services\Sanad;

use App\Actions\Sanad\AcknowledgeSanadOutboxItemAction;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Support\DTOs\SanadOutboxPage;

/** Serves the outbox to Sanad (contract 3.1) and records its acknowledgements (3.2). */
final class SanadOutboxService
{
    public function __construct(private readonly AcknowledgeSanadOutboxItemAction $acknowledgeItem) {}

    /**
     * Pending items of this device after the cursor, oldest first. Re-reading is safe: items stay until acked.
     */
    public function page(SanadDevice $device, ?string $cursor, ?int $limit = null): SanadOutboxPage
    {
        $pageSize = config('sanad.outbox.page_size');
        $limit = min($limit ?? $pageSize, $pageSize);

        // "C" collation: plain byte order, the order ULIDs are generated in.
        $rows = SanadOutbox::query()
            ->where('device_id', $device->getKey())
            ->where('status', SanadOutboxStatus::Pending)
            ->when($cursor !== null && $cursor !== '', fn ($query) => $query->whereRaw('id collate "C" > ?', [$cursor]))
            ->orderByRaw('id collate "C"')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit)->values();

        return new SanadOutboxPage(
            $page->map(fn (SanadOutbox $row): array => $this->present($row))->all(),
            $page->isEmpty() ? $cursor : $page->last()->id,
            $rows->count() > $limit,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $acks
     * @return array{acked: int, ignored: int}
     */
    public function acknowledge(SanadDevice $device, array $acks): array
    {
        $acked = 0;
        foreach ($acks as $ack) {
            if (($this->acknowledgeItem)($device, $ack)) {
                $acked++;
            }
        }

        return ['acked' => $acked, 'ignored' => count($acks) - $acked];
    }

    /**
     * `produced_at` is additive (unknown fields are ignored per contract section 0): Sanad uses it to
     * detect a local price edit made after Formula Lab produced a price suggestion.
     *
     * @return array<string, mixed>
     */
    private function present(SanadOutbox $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->kind->value,
            'external_id' => $row->external_id,
            'produced_at' => $row->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'payload' => $row->payload,
        ];
    }
}
