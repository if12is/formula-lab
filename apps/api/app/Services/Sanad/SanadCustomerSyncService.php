<?php

namespace App\Services\Sanad;

use App\Exceptions\Domain\SanadIntegrationException;
use App\Integrations\Sanad\SanadClient;
use App\Support\DTOs\SanadPullResult;

/**
 * Customers over LAN (contract 8.5): reads the customers feed page by page with its own cursor and applies each page through
 * the same SanadChangesService the push uses, so a pulled and a pushed customer end in the same read-only cache and ref.
 * Idempotent: re-reading a page changes nothing. Customers are never created here, only mirrored.
 */
final class SanadCustomerSyncService
{
    public function __construct(
        private readonly SanadClient $client,
        private readonly SanadChangesService $changes,
    ) {}

    /**
     * Stops after `max_pages_per_pull` pages with `hasMore` set; pass the returned cursor back to continue.
     *
     * @param  array<string, ?string>  $cursors  `['customer' => cursor]` from the previous run
     *
     * @throws SanadIntegrationException
     */
    public function pull(string $tenantId, array $cursors = [], ?string $updatedSince = null): SanadPullResult
    {
        $maxPages = config('sanad.client.max_pages_per_pull');
        $cursor = $cursors['customer'] ?? null;
        $accepted = $conflicts = $pages = 0;
        $hasMore = false;

        do {
            if ($pages >= $maxPages) {
                $hasMore = true;
                break;
            }

            $page = $this->client->customers($cursor, $updatedSince);
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
        } while ($page->hasMore());

        return new SanadPullResult($accepted, $conflicts, $pages, ['customer' => $cursor], $hasMore);
    }

    /**
     * A feed row as a pushed item (contract section 2). Null when it cannot be identified.
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
                ? ['entity' => 'customer', 'uuid' => $uuid, 'op' => 'delete', 'updated_at' => $deletedAt, 'payload' => ['deleted_at' => $deletedAt]]
                : null;
        }

        $updatedAt = $row['updated_at'] ?? null;

        return is_string($updatedAt)
            ? ['entity' => 'customer', 'uuid' => $uuid, 'op' => 'upsert', 'updated_at' => $updatedAt, 'payload' => $row]
            : null;
    }
}
