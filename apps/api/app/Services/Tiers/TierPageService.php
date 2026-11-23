<?php

namespace App\Services\Tiers;

use App\Enums\ProductTier;
use App\Enums\SourceKind;
use App\Models\Formula;

/** What the tiers screen is opened with: the chosen product and tier, the saved profile as a form, and the addresses of the selectors. */
final class TierPageService
{
    public function __construct(private readonly TierProfileService $profiles) {}

    /**
     * @return array<string, mixed>
     */
    public function props(?Formula $formula, ?ProductTier $tier): array
    {
        $tier ??= $formula?->tier ?? ProductTier::Economy;

        return [
            'product' => $formula === null ? null : [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'product_type' => $formula->product_type,
                'tier' => $formula->tier?->value,
            ],
            'tier' => $tier->value,
            'tierOptions' => ProductTier::options(),
            'profile' => $formula === null ? null : $this->profiles->form($formula->product_type, $tier),
            'sourceKindOptions' => SourceKind::selectableOptions(),
            'urls' => [
                'products' => route('price-lists.products.search', [], false),
                'materials' => route('materials.search', [], false),
                'sources' => route('sources.search', [], false),
                'sourceStore' => route('sources.store', [], false),
                'save' => route('tiers.profile.save', [], false),
                'solve' => route('tiers.solve', [], false),
                'saveVersion' => route('tiers.versions.store', [], false),
            ],
        ];
    }
}
