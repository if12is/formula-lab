<?php

namespace App\Actions\Sanad;

use App\Actions\Sanad\Concerns\ReadsSanadChange;
use App\Enums\Sanad\SanadChangeStatus;
use App\Enums\Sanad\SanadConflictReason;
use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadRefType;
use App\Models\SanadSupplier;
use App\Support\DTOs\SanadChangeOutcome;
use App\Support\RequestFingerprint;
use Illuminate\Support\Str;

/** Applies a pushed Sanad supplier to the read-only cache (name and payment terms only; money stays in Sanad). */
final class ApplySanadSupplierChangeAction
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
        if (! $isDelete && $this->payloadIsInvalid(SanadEntity::Supplier, $item)) {
            return SanadChangeOutcome::conflict(SanadConflictReason::SchemaMismatch);
        }

        $existing = SanadSupplier::query()->find($uuid);
        if ($existing !== null && $existing->tenant_id !== $tenantId) {
            return SanadChangeOutcome::conflict(SanadConflictReason::InvalidReference);
        }

        $updatedAt = $this->updatedAt($item);
        $outcome = ($this->upsertRef)($tenantId, SanadRefType::Supplier, $uuid, $updatedAt, RequestFingerprint::hash($payload), $isDelete);
        if ($outcome->status !== SanadChangeStatus::Applied) {
            return $outcome;
        }

        if ($isDelete) {
            $existing?->forceFill(['deleted' => true, 'updated_at' => $updatedAt, 'synced_at' => now()])->save();

            return $outcome;
        }

        SanadSupplier::query()->updateOrCreate(['uuid' => $uuid], [
            'tenant_id' => $tenantId,
            'code' => $payload['code'] ?? null,
            'name' => $payload['name'],
            'name_en' => $payload['name_en'] ?? null,
            'tax_number' => $payload['tax_number'] ?? null,
            'payment_terms' => $payload['payment_terms'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'updated_at' => $updatedAt,
            'deleted' => false,
            'synced_at' => now(),
        ]);

        return $outcome;
    }
}
