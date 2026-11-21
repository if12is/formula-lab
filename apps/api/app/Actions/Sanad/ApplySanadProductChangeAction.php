<?php

namespace App\Actions\Sanad;

use App\Actions\Sanad\Concerns\ReadsSanadChange;
use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadItemKind;
use App\Enums\Sanad\SanadRefType;
use App\Models\SanadProduct;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\RequestFingerprint;
use Illuminate\Support\Str;

/**
 * Applies a pushed Sanad product to the read-only cache. Only raw materials, packaging and finished
 * goods are cached; point-of-sale merchandise is accepted and ignored (a cached product that turns
 * into merchandise is retired like a tombstone).
 */
final class ApplySanadProductChangeAction
{
    use ReadsSanadChange;

    public function __construct(private readonly UpsertSanadRefAction $upsertRef) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function __invoke(string $tenantId, array $item): SanadChangeOutcome
    {
        $uuid = Str::lower((string) $item['uuid']);
        $isDelete = $this->isDelete($item);
        $payload = (array) $item['payload'];

        if (! $this->hasValidUuid($item) || (! $isDelete && $this->payloadNamesAnotherUuid($item))) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }
        if (! $isDelete && $this->payloadIsInvalid(SanadEntity::Product, $item)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::SchemaMismatch);
        }

        $existing = SanadProduct::query()->find($uuid);
        if ($existing !== null && $existing->tenant_id !== $tenantId) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }

        $cached = ! $isDelete && in_array($payload['item_kind'], config('sanad.cached_item_kinds'), true);
        if (! $isDelete && ! $cached && $existing === null) {
            return SanadChangeOutcome::unchanged();
        }

        $updatedAt = $this->updatedAt($item);
        $outcome = ($this->upsertRef)($tenantId, SanadRefType::Product, $uuid, $updatedAt, RequestFingerprint::hash($payload), ! $cached);
        if ($outcome->status !== SanadChangeStatus::Applied) {
            return $outcome;
        }

        if (! $cached) {
            $existing?->forceFill(['deleted' => true, 'updated_at' => $updatedAt, 'synced_at' => now()])->save();

            return $outcome;
        }

        SanadProduct::query()->updateOrCreate(['uuid' => $uuid], [
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'name_en' => $payload['name_en'] ?? null,
            'sku' => $payload['sku'] ?? null,
            'barcode' => $payload['barcode'] ?? null,
            'item_kind' => SanadItemKind::from($payload['item_kind']),
            'is_sellable' => (bool) ($payload['is_sellable'] ?? false),
            'uom' => $payload['uom']['name'] ?? null,
            'units' => $payload['units'] ?? [],
            'cost_price' => $payload['cost_price'] ?? null,
            'tax_rate' => $payload['tax_rate'] ?? null,
            'updated_at' => $updatedAt,
            'deleted' => false,
            'synced_at' => now(),
        ]);

        return $outcome;
    }
}
