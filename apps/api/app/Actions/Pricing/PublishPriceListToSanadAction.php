<?php

namespace App\Actions\Pricing;

use App\Actions\Sanad\EnqueueSanadMessageAction;
use App\Enums\PriceListSyncStatus;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\PriceListException;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Integrations\Sanad\SanadClient;
use App\Models\PriceList;
use App\Models\SanadCustomer;
use App\Models\SanadDevice;
use App\Support\DTOs\PriceListPayload;
use App\Support\DTOs\PriceListPublishResult;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a price list to Sanad under its own uuid: queued as a `price_list_upsert` outbox change for the Sanad-initiated
 * link, or sent over LAN when no device is linked. Publishing again updates the same list (Sanad upserts by uuid). It is
 * idempotent: a list whose content did not change since a publish that is waiting or was applied queues nothing new, so
 * pressing the button twice never sends it twice; after a failure or a conflict the same content is sent again.
 */
final class PublishPriceListToSanadAction
{
    public function __construct(
        private readonly BuildPriceListPayloadAction $buildPayload,
        private readonly EnqueueSanadMessageAction $enqueue,
        private readonly SanadClient $client,
    ) {}

    /**
     * @throws PriceListException When the list is not ready (products unpublished, prices incomplete, too long) or no Sanad link exists.
     * @throws SanadIntegrationException In LAN mode, when Sanad refuses a request.
     */
    public function __invoke(PriceList $list, ?SanadDevice $device = null): PriceListPublishResult
    {
        if ($device !== null && $device->tenant_id !== $list->tenant_id) {
            throw PriceListException::crossTenant();
        }
        if ($device === null && ! $this->lanConfigured()) {
            throw PriceListException::noSanadLink();
        }

        $payload = ($this->buildPayload)($list);
        $hash = $payload->hash();

        if ($list->published_hash === $hash && in_array($list->sync_status, [PriceListSyncStatus::Pending, PriceListSyncStatus::Applied], true)) {
            return new PriceListPublishResult($list->getKey(), $device === null ? 'lan' : 'outbox', $list->sync_outbox_id, unchanged: true);
        }

        return $device === null ? $this->sendOverLan($list, $payload, $hash) : $this->queue($list, $device, $payload, $hash);
    }

    private function queue(PriceList $list, SanadDevice $device, PriceListPayload $payload, string $hash): PriceListPublishResult
    {
        return DB::transaction(function () use ($list, $device, $payload, $hash): PriceListPublishResult {
            $row = ($this->enqueue)($device, SanadOutboxKind::PriceListUpsert, $payload->body, $list->getKey());
            $this->remember($list, $payload, $hash, PriceListSyncStatus::Pending, $row->id, null);

            return new PriceListPublishResult($list->getKey(), 'outbox', $row->id);
        });
    }

    /**
     * LAN: the list with its items first, then each customer assignment. A customer is detached only when Sanad's own copy
     * (the cache) says it is on this list, like the outbox path does (contract 8.6).
     */
    private function sendOverLan(PriceList $list, PriceListPayload $payload, string $hash): PriceListPublishResult
    {
        $body = $payload->body;
        $responses = [$this->client->upsertPriceList($list->getKey(), array_diff_key($body, ['uuid' => true, 'assign_customers' => true, 'unassign_customers' => true]))];

        foreach ($body['assign_customers'] as $customerUuid) {
            $responses[] = $this->client->assignCustomerPriceList($customerUuid, $list->getKey());
        }
        $onThisList = SanadCustomer::query()->whereIn('uuid', $body['unassign_customers'])->where('price_list_uuid', $list->getKey())->pluck('uuid');
        foreach ($onThisList as $customerUuid) {
            $responses[] = $this->client->assignCustomerPriceList($customerUuid, null);
        }

        $this->remember($list, $payload, $hash, PriceListSyncStatus::Applied, null, ['responses' => count($responses)]);

        return new PriceListPublishResult($list->getKey(), 'lan', responses: $responses);
    }

    /** @param  array<string, mixed>|null  $detail */
    private function remember(PriceList $list, PriceListPayload $payload, string $hash, PriceListSyncStatus $status, ?string $outboxId, ?array $detail): void
    {
        $list->forceFill([
            'sync_status' => $status,
            'sync_outbox_id' => $outboxId,
            'sync_detail' => $detail,
            'published_hash' => $hash,
            'published_at' => now(),
            'published_items' => $payload->itemUuids,
            'published_customers' => $payload->customerUuids,
        ])->save();
    }

    private function lanConfigured(): bool
    {
        return (string) config('sanad.client.base_url') !== '' && (string) config('sanad.client.token') !== '';
    }
}
