<?php

namespace App\Actions\Sanad\Concerns;

use App\Enums\Sanad\SanadEntity;
use App\Enums\Sanad\SanadOp;
use App\Support\Sanad\ChangePayloadRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Shared parsing of one pushed item: `{entity, uuid, op, updated_at, payload}` (contract section 2). */
trait ReadsSanadChange
{
    /** @param  array<string, mixed>  $item */
    private function updatedAt(array $item): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $item['updated_at'])->utc();
    }

    /** @param  array<string, mixed>  $item */
    private function isDelete(array $item): bool
    {
        return $item['op'] === SanadOp::Delete->value;
    }

    /** @param  array<string, mixed>  $item */
    private function hasValidUuid(array $item): bool
    {
        return Str::isUuid((string) $item['uuid']);
    }

    /**
     * True when the payload does not fit the contract shape or names a different uuid than the item.
     *
     * @param  array<string, mixed>  $item
     */
    private function payloadIsInvalid(SanadEntity $entity, array $item): bool
    {
        $payload = (array) $item['payload'];

        return Validator::make($payload, ChangePayloadRules::for($entity))->fails();
    }

    /** @param  array<string, mixed>  $item */
    private function payloadNamesAnotherUuid(array $item): bool
    {
        $payload = (array) $item['payload'];

        return isset($payload['uuid']) && Str::lower((string) $payload['uuid']) !== Str::lower((string) $item['uuid']);
    }
}
