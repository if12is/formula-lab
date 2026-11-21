<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadItemKind;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Integrations\Sanad\SanadClient;
use App\Models\SanadDevice;
use App\Models\TradeMaterial;
use App\Support\DTOs\SanadPublishResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers a trade material that has no Sanad product yet as a raw-material or packaging product
 * (is_sellable = false): queued in the outbox for the Sanad-initiated link, or upserted directly in LAN mode
 * (no device). Formula Lab never creates commerce tables; Sanad owns the product from then on, so a
 * material that is already linked is refused instead of overwritten.
 */
final class PublishMaterialToSanadAction
{
    public function __construct(
        private readonly EnqueueSanadMessageAction $enqueue,
        private readonly SanadClient $client,
    ) {}

    /**
     * @throws SanadLinkException When the material is already linked, the unit is missing or the kind is not a material kind.
     * @throws SanadIntegrationException In LAN mode, when Sanad refuses the upsert.
     */
    public function __invoke(
        TradeMaterial $material,
        string $uom,
        SanadItemKind $kind = SanadItemKind::RawMaterial,
        ?SanadDevice $device = null,
    ): SanadPublishResult {
        if ($material->sanad_product_uuid !== null) {
            throw SanadLinkException::materialAlreadyLinked($material->getKey());
        }
        if (trim($uom) === '') {
            throw SanadLinkException::uomRequired();
        }
        if (! in_array($kind, [SanadItemKind::RawMaterial, SanadItemKind::Packaging], true)) {
            throw SanadLinkException::kindNotPublishable($kind->value);
        }
        $tenantId = $device?->tenant_id ?? $material->tenant_id;
        if ($tenantId === null) {
            throw SanadLinkException::tenantRequired();
        }
        if ($material->tenant_id !== $tenantId) {
            throw SanadLinkException::tenantMismatch();
        }

        $uuid = (string) Str::uuid();
        $body = [
            'name' => $material->trade_name,
            'item_kind' => $kind->value,
            'is_sellable' => false,
            'uom' => trim($uom),
            'external_id' => $material->getKey(),
        ];

        if ($device !== null) {
            return DB::transaction(function () use ($device, $material, $uuid, $body): SanadPublishResult {
                $row = ($this->enqueue)($device, SanadOutboxKind::ProductUpsert, ['uuid' => $uuid] + $body);
                $material->forceFill(['sanad_product_uuid' => $uuid])->save();

                return new SanadPublishResult($uuid, 'outbox', outboxId: $row->id);
            });
        }

        $response = $this->client->upsertProduct($uuid, $body);
        $material->forceFill(['sanad_product_uuid' => $uuid])->save();

        return new SanadPublishResult($uuid, 'lan', response: $response);
    }
}
