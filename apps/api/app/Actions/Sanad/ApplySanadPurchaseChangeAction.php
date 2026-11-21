<?php

namespace App\Actions\Sanad;

use App\Actions\Sanad\Concerns\ReadsSanadChange;
use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadRefType;
use App\Models\SanadPurchaseLine;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\RequestFingerprint;
use Illuminate\Support\Str;

/**
 * Rebuilds the cached lines of one pushed Sanad purchase (items are embedded in the payload).
 * A tombstone removes the lines. The cache is rebuildable: Sanad wins on any difference.
 */
final class ApplySanadPurchaseChangeAction
{
    use ReadsSanadChange;

    public function __construct(private readonly UpsertSanadRefAction $upsertRef) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function __invoke(string $tenantId, array $item): SanadChangeOutcome
    {
        $purchaseUuid = Str::lower((string) $item['uuid']);
        $isDelete = $this->isDelete($item);
        $payload = (array) $item['payload'];

        if (! $this->hasValidUuid($item) || (! $isDelete && $this->payloadNamesAnotherUuid($item))) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }
        if (! $isDelete && $this->payloadIsInvalid(SanadEntity::Purchase, $item)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::SchemaMismatch);
        }

        $lines = $isDelete ? [] : array_values((array) $payload['items']);
        $lineUuids = array_map(fn (array $line): string => Str::lower((string) $line['uuid']), $lines);
        if ($lineUuids !== [] && SanadPurchaseLine::query()->whereIn('sanad_uuid', $lineUuids)->where('tenant_id', '!=', $tenantId)->exists()) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }

        $updatedAt = $this->updatedAt($item);
        $outcome = ($this->upsertRef)($tenantId, SanadRefType::Purchase, $purchaseUuid, $updatedAt, RequestFingerprint::hash($payload), $isDelete);
        if ($outcome->status !== SanadChangeStatus::Applied) {
            return $outcome;
        }

        SanadPurchaseLine::query()
            ->where('tenant_id', $tenantId)
            ->where('sanad_purchase_uuid', $purchaseUuid)
            ->whereNotIn('sanad_uuid', $lineUuids)
            ->delete();

        foreach ($lines as $index => $line) {
            SanadPurchaseLine::query()->updateOrCreate(['sanad_uuid' => $lineUuids[$index]], [
                'tenant_id' => $tenantId,
                'sanad_purchase_uuid' => $purchaseUuid,
                'sanad_supplier_uuid' => $payload['supplier_uuid'] ?? null,
                'sanad_product_uuid' => Str::lower((string) $line['product_uuid']),
                'purchase_date' => $payload['purchase_date'],
                'purchase_status' => $payload['status'],
                'quantity' => $line['quantity'] ?? null,
                'purchase_uom' => $line['uom_name'] ?? null,
                'base_quantity' => $line['base_quantity'] ?? null,
                'received_quantity' => $line['received_quantity'] ?? null,
                'unit_cost' => $line['unit_cost'] ?? null,
                'discount_amount' => $line['discount_amount'] ?? null,
                'tax_amount' => $line['tax_amount'] ?? null,
                'subtotal' => $line['subtotal'] ?? null,
                'currency' => $payload['currency'] ?? 'EGP',
                'batch_number' => $line['batch_number'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'production_date' => $line['production_date'] ?? null,
                'sanad_updated_at' => $updatedAt,
                'synced_at' => now(),
            ]);
        }

        return $outcome;
    }
}
