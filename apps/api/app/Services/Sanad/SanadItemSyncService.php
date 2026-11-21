<?php

namespace App\Services\Sanad;

use App\Actions\Sanad\LinkTradeMaterialToSanadProductAction;
use App\Actions\Sanad\PublishMaterialToSanadAction;
use App\Enums\Sanad\SanadItemKind;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Integrations\Sanad\SanadClient;
use App\Models\SanadDevice;
use App\Models\SanadProduct;
use App\Models\TradeMaterial;
use App\Support\DTOs\SanadPublishResult;
use App\Support\DTOs\SanadPullResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Item sync (G-05). Products arrive either pushed over /changes (SanadChangesService) or pulled over LAN
 * here; both end in the same cache + sanad_refs, and a material that is already linked stays in step.
 * Raw materials and packaging are linked to trade materials explicitly; unlinked ones are listed as candidates.
 * Formula Lab never creates commerce tables: a material with no Sanad product is published, not stored.
 */
final class SanadItemSyncService
{
    public function __construct(
        private readonly SanadClient $client,
        private readonly SanadChangesService $changes,
        private readonly LinkTradeMaterialToSanadProductAction $linkMaterial,
        private readonly PublishMaterialToSanadAction $publishMaterial,
    ) {}

    /**
     * Reads every cached kind from Sanad (LAN mode), a kind at a time with its own cursor, and applies it.
     * Idempotent: re-reading the same pages changes nothing. Stops after `max_pages_per_pull` pages with
     * `hasMore` set; pass the returned cursors back to continue.
     *
     * @param  array<string, ?string>  $cursors  kind => cursor from the previous run
     *
     * @throws SanadIntegrationException
     */
    public function pull(string $tenantId, array $cursors = [], ?string $updatedSince = null): SanadPullResult
    {
        $maxPages = config('sanad.client.max_pages_per_pull');
        $accepted = $conflicts = $pages = 0;
        $hasMore = false;

        foreach (config('sanad.cached_item_kinds') as $kind) {
            $cursor = $cursors[$kind] ?? null;

            do {
                if ($pages >= $maxPages) {
                    $hasMore = true;
                    break;
                }

                $page = $this->client->products($cursor, $kind, $updatedSince);
                $pages++;

                $items = [];
                foreach ($page->data as $row) {
                    $item = $this->toChangeItem($row);
                    if ($item === null) {
                        $conflicts++;

                        continue;
                    }
                    $items[] = $item;
                }

                $result = $this->changes->applyItems($tenantId, $items);
                $conflicts += count($result->conflicts);
                $accepted += $result->accepted;
                $cursor = $page->nextCursor ?? $cursor;
                $cursors[$kind] = $cursor;
            } while ($page->hasMore());
        }

        return new SanadPullResult($accepted, $conflicts, $pages, $cursors, $hasMore);
    }

    /**
     * Cached raw materials and packaging of a tenant that no trade material is linked to yet.
     *
     * @return Collection<int, SanadProduct>
     */
    public function linkCandidates(string $tenantId, ?SanadItemKind $kind = null): Collection
    {
        $linked = TradeMaterial::query()->where('tenant_id', $tenantId)->whereNotNull('sanad_product_uuid')->pluck('sanad_product_uuid');
        $kinds = $kind === null ? [SanadItemKind::RawMaterial, SanadItemKind::Packaging] : [$kind];

        return SanadProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('deleted', false)
            ->whereIn('item_kind', $kinds)
            ->whereNotIn('uuid', $linked)
            ->orderBy('name')
            ->get();
    }

    /**
     * @throws SanadLinkException
     */
    public function link(TradeMaterial $material, SanadProduct $product): TradeMaterial
    {
        return ($this->linkMaterial)($material, $product);
    }

    /**
     * @throws SanadLinkException
     * @throws SanadIntegrationException
     */
    public function publish(TradeMaterial $material, string $uom, SanadItemKind $kind = SanadItemKind::RawMaterial, ?SanadDevice $device = null): SanadPublishResult
    {
        return ($this->publishMaterial)($material, $uom, $kind, $device);
    }

    /**
     * A feed row (contract 4.1) as a pushed item (contract section 2). Null when it cannot be identified.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function toChangeItem(array $row): ?array
    {
        $uuid = $row['uuid'] ?? null;
        if (! is_string($uuid)) {
            return null;
        }

        if (($row['deleted'] ?? false) === true) {
            $deletedAt = $row['deleted_at'] ?? null;

            return is_string($deletedAt)
                ? ['entity' => 'product', 'uuid' => $uuid, 'op' => 'delete', 'updated_at' => $deletedAt, 'payload' => ['deleted_at' => $deletedAt]]
                : null;
        }

        $updatedAt = $row['updated_at'] ?? null;

        return is_string($updatedAt)
            ? ['entity' => 'product', 'uuid' => $uuid, 'op' => 'upsert', 'updated_at' => $updatedAt, 'payload' => $row]
            : null;
    }
}
