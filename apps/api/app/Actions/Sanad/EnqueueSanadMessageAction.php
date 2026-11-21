<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadOutboxKind;
use App\Enums\Sanad\SanadOutboxStatus;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Support\RequestFingerprint;
use App\Support\Sanad\NoFloats;
use Illuminate\Support\Str;

/**
 * Queues a message for Sanad to pull (contract 3.1). A production order is immutable once queued:
 * the same external id with the same content returns the existing row, with different content it is refused.
 */
final class EnqueueSanadMessageAction
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SanadIntegrationException When the payload holds a float instead of a decimal string.
     * @throws SanadLinkException When a published production order would change.
     */
    public function __invoke(SanadDevice $device, SanadOutboxKind $kind, array $payload, ?string $externalId = null): SanadOutbox
    {
        NoFloats::assert($payload);

        $externalId ??= match ($kind) {
            SanadOutboxKind::ProductionOrder => $payload['external_id'] ?? null,
            SanadOutboxKind::FinishedGood => $payload['uuid'] ?? null,
            SanadOutboxKind::WholesalePrices => $payload['product_uuid'] ?? null,
        };

        if ($kind === SanadOutboxKind::ProductionOrder && $externalId !== null) {
            $existing = SanadOutbox::query()
                ->where('device_id', $device->getKey())
                ->where('kind', $kind)
                ->where('external_id', $externalId)
                ->whereNull('retry_of')
                ->first();
            if ($existing !== null) {
                if (RequestFingerprint::hash($existing->payload) !== RequestFingerprint::hash($payload)) {
                    throw SanadLinkException::productionOrderImmutable($externalId);
                }

                return $existing;
            }
        }

        return SanadOutbox::query()->create([
            'id' => config('sanad.outbox.id_prefix').Str::lower((string) Str::ulid()),
            'device_id' => $device->getKey(),
            'kind' => $kind,
            'external_id' => $externalId === null ? null : (string) $externalId,
            'payload' => $payload,
            'status' => SanadOutboxStatus::Pending,
            'created_at' => now(),
        ]);
    }
}
