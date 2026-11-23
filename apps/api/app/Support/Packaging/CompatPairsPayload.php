<?php

namespace App\Support\Packaging;

use App\Models\PackagingCompatibility;

/** The declared neck finish pairs as the engine reads them (checkPackagingCompatibility's `pairs`): one shape for the BOM check and the BOM cost. */
final class CompatPairsPayload
{
    /** @return list<array{containerNeck: string, closureNeck: string, sourceId: string, confidence: string, validFrom: string|null}> */
    public static function load(): array
    {
        return PackagingCompatibility::query()->with('source')->get()
            ->map(fn (PackagingCompatibility $pair): array => [
                'containerNeck' => $pair->container_neck_finish,
                'closureNeck' => $pair->closure_neck_finish,
                'sourceId' => (string) $pair->source_id,
                'confidence' => $pair->confidence->value,
                'validFrom' => $pair->source?->retrieved_at?->toDateString(),
            ])
            ->all();
    }
}
