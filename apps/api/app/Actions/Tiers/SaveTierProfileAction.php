<?php

namespace App\Actions\Tiers;

use App\Enums\ProductTier;
use App\Models\TierProfile;

/** Writes the profile of one tier of one product type: the first save creates it, the next ones replace it (the audit log keeps the history). */
final class SaveTierProfileAction
{
    /**
     * @param  array<string, mixed>  $profile  The engine's profile body: `targetRetailPrice` and `constraints`.
     */
    public function __invoke(string $tenantId, string $productType, ProductTier $tier, array $profile, ?string $sourceId): TierProfile
    {
        return TierProfile::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'product_type' => $productType, 'tier' => $tier],
            ['profile' => $profile, 'source_id' => $sourceId],
        );
    }
}
