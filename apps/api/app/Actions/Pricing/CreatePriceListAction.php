<?php

namespace App\Actions\Pricing;

use App\Enums\PriceListSyncStatus;
use App\Models\PriceList;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

/** Creates an empty price list (draft, EGP). The uuid it gets is the uuid it keeps in Sanad. */
final class CreatePriceListAction
{
    /**
     * @param  array{name: string, is_active?: bool}  $data
     *
     * @throws QueryException When the name is already used inside the tenant.
     */
    public function __invoke(Tenant $tenant, array $data): PriceList
    {
        return PriceList::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $data['name'],
            'currency' => config('pricing.currency'),
            'is_active' => $data['is_active'] ?? true,
            'sync_status' => PriceListSyncStatus::Draft,
        ]);
    }
}
