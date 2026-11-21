<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Integrations\Sanad\SanadClient;
use App\Models\Formula;
use App\Models\SanadDevice;
use App\Support\DTOs\SanadPublishResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publishes a formula's finished good to Sanad with its calculated unit cost, suggested price and tax setting: queued in
 * the outbox for the Sanad-initiated link, or upserted directly in LAN mode (no device). The Sanad product uuid is kept on
 * the formula, so publishing again updates the same product (the upsert is idempotent by uuid).
 */
final class PublishFinishedGoodToSanadAction
{
    public function __construct(
        private readonly BuildFinishedGoodPayloadAction $buildPayload,
        private readonly EnqueueSanadMessageAction $enqueue,
        private readonly SanadClient $client,
    ) {}

    /**
     * @param  array{uom: string, cost_price: string, sell_price?: string|null, sku?: string|null, name_en?: string|null}  $details
     *
     * @throws SanadLinkException When the unit is missing, a price is not a decimal string or the tenant does not match.
     * @throws InvalidTaxSettingException When no tax was chosen, or the chosen rate has no regime in force.
     * @throws SanadIntegrationException In LAN mode, when Sanad refuses the upsert.
     */
    public function __invoke(Formula $formula, array $details, ?SanadDevice $device = null): SanadPublishResult
    {
        if ($device !== null && $device->tenant_id !== $formula->tenant_id) {
            throw SanadLinkException::tenantMismatch();
        }

        $uuid = $formula->sanad_product_uuid ?? (string) Str::uuid();
        $body = ($this->buildPayload)($formula, $uuid, $details);

        if ($device !== null) {
            return DB::transaction(function () use ($device, $formula, $uuid, $body): SanadPublishResult {
                $row = ($this->enqueue)($device, SanadOutboxKind::ProductUpsert, $body);
                $formula->forceFill(['sanad_product_uuid' => $uuid])->save();

                return new SanadPublishResult($uuid, 'outbox', outboxId: $row->id);
            });
        }

        $response = $this->client->upsertProduct($uuid, array_diff_key($body, ['uuid' => true]));
        $formula->forceFill(['sanad_product_uuid' => $uuid])->save();

        return new SanadPublishResult($uuid, 'lan', response: $response);
    }
}
