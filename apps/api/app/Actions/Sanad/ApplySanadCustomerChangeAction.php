<?php

namespace App\Actions\Sanad;

use App\Actions\Sanad\Concerns\ReadsSanadChange;
use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadRefType;
use App\Models\SanadCustomer;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\RequestFingerprint;
use Illuminate\Support\Str;

/**
 * Applies a Sanad customer (contract 8.5) to the read-only cache: uuid, code, names, active and wholesale flags and the uuid
 * of the price list the customer is on, and nothing else (no balances, phones or addresses). Customers are never created or
 * edited in Formula Lab; an archived one arrives as a tombstone and stays in the cache marked deleted.
 */
final class ApplySanadCustomerChangeAction
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
        if (! $isDelete && $this->payloadIsInvalid(SanadEntity::Customer, $item)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::SchemaMismatch);
        }

        $existing = SanadCustomer::query()->find($uuid);
        if ($existing !== null && $existing->tenant_id !== $tenantId) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }

        $updatedAt = $this->updatedAt($item);
        $outcome = ($this->upsertRef)($tenantId, SanadRefType::Customer, $uuid, $updatedAt, RequestFingerprint::hash($payload), $isDelete);
        if ($outcome->status !== SanadChangeStatus::Applied) {
            return $outcome;
        }

        if ($isDelete) {
            $existing?->forceFill(['deleted' => true, 'updated_at' => $updatedAt, 'synced_at' => now()])->save();

            return $outcome;
        }

        SanadCustomer::query()->updateOrCreate(['uuid' => $uuid], [
            'tenant_id' => $tenantId,
            'code' => $payload['code'] ?? null,
            'name' => $payload['name'],
            'name_en' => $payload['name_en'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'is_wholesale' => (bool) ($payload['is_wholesale'] ?? false),
            'price_list_uuid' => isset($payload['price_list_uuid']) ? Str::lower((string) $payload['price_list_uuid']) : null,
            'updated_at' => $updatedAt,
            'deleted' => false,
            'synced_at' => now(),
        ]);

        return $outcome;
    }
}
