<?php

namespace App\Services\Sanad;

use App\Actions\Sanad\ApplySanadBatchChangeAction;
use App\Actions\Sanad\ApplySanadProductChangeAction;
use App\Actions\Sanad\ApplySanadPurchaseChangeAction;
use App\Actions\Sanad\ApplySanadSupplierChangeAction;
use App\Actions\Sanad\ReconcileTradeMaterialLinkAction;
use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadEntity;
use App\Models\SanadDevice;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\DTOs\SanadChangesResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a batch pushed by Sanad (contract section 2) and the same items read over LAN. The batch is
 * atomic: any exception rolls everything back, so Sanad can retry with the same Idempotency-Key.
 * An item that is stale or malformed is not applied and is reported in `conflicts` instead.
 */
final class SanadChangesService
{
    public function __construct(
        private readonly ApplySanadProductChangeAction $applyProduct,
        private readonly ApplySanadSupplierChangeAction $applySupplier,
        private readonly ApplySanadPurchaseChangeAction $applyPurchase,
        private readonly ApplySanadBatchChangeAction $applyBatch,
        private readonly ReconcileTradeMaterialLinkAction $reconcileMaterialLink,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  ordered by (updated_at, uuid)
     */
    public function apply(SanadDevice $device, array $items, ?string $cursor): SanadChangesResult
    {
        return DB::transaction(function () use ($device, $items, $cursor): SanadChangesResult {
            $result = $this->applyItems($device->tenant_id, $items);

            $lastEntity = $items === [] ? null : (string) end($items)['entity'];
            if ($lastEntity !== null && $cursor !== null) {
                $device->forceFill(['push_cursor' => [...(array) $device->push_cursor, $lastEntity => $cursor]])->save();
            }

            return new SanadChangesResult(
                $result->accepted,
                $cursor ?? ($lastEntity === null ? null : ($device->push_cursor[$lastEntity] ?? null)),
                $result->conflicts,
                $result->skipped,
            );
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function applyItems(string $tenantId, array $items): SanadChangesResult
    {
        return DB::transaction(function () use ($tenantId, $items): SanadChangesResult {
            $accepted = 0;
            $conflicts = [];
            $skipped = [];

            foreach ($items as $item) {
                if (SanadEntity::tryFrom((string) $item['entity']) === null) {
                    $skipped[] = ['entity' => (string) $item['entity'], 'uuid' => (string) $item['uuid'], 'reason' => 'unknown_entity'];

                    continue;
                }

                $outcome = $this->applyItem($tenantId, $item);

                if ($outcome->isConflict()) {
                    $conflicts[] = $this->conflictEntry($item, $outcome);
                } else {
                    $accepted++;
                }
            }

            return new SanadChangesResult($accepted, null, $conflicts, $skipped);
        });
    }

    /** @param  array<string, mixed>  $item */
    private function applyItem(string $tenantId, array $item): SanadChangeOutcome
    {
        $entity = SanadEntity::from((string) $item['entity']);

        $outcome = match ($entity) {
            SanadEntity::Product => ($this->applyProduct)($tenantId, $item),
            SanadEntity::Supplier => ($this->applySupplier)($tenantId, $item),
            SanadEntity::Purchase => ($this->applyPurchase)($tenantId, $item),
            SanadEntity::Batch => ($this->applyBatch)($tenantId, $item),
            // Accepted but not stored: Formula Lab only needs movements in the actual-cost phase (Q).
            SanadEntity::StockMovement => SanadChangeOutcome::unchanged(),
        };

        if ($entity === SanadEntity::Product && $outcome->status === SanadChangeStatus::Applied) {
            ($this->reconcileMaterialLink)($tenantId, (string) $item['uuid']);
        }

        return $outcome;
    }

    /**
     * Contract section 2 conflict entry. local_* describes the copy Formula Lab already holds, central_*
     * what Sanad just sent.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function conflictEntry(array $item, SanadChangeOutcome $outcome): array
    {
        $localUpdatedAt = $outcome->localUpdatedAt?->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'entity' => $item['entity'],
            'uuid' => $item['uuid'],
            'reason' => $outcome->reason?->value,
            'local_updated_at' => $localUpdatedAt,
            'central_updated_at' => CarbonImmutable::parse((string) $item['updated_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'local_payload' => $localUpdatedAt === null ? [] : ['uuid' => $item['uuid'], 'updated_at' => $localUpdatedAt],
            'central_payload' => (object) (array) $item['payload'],
        ];
    }
}
