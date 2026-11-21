<?php

namespace App\Actions\Formula;

use App\Enums\ProductTier;
use App\Models\Formula;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

/** Inserts the identity row of a formula (code, name, product type, tier). Versions come separately. */
final class CreateFormulaAction
{
    /**
     * @param  array{code: string, name: string, product_type: string, tier?: ProductTier|string|null}  $data
     *
     * @throws QueryException When the code is already used inside the tenant.
     */
    public function __invoke(Tenant $tenant, array $data): Formula
    {
        return Formula::query()->create([...$data, 'tenant_id' => $tenant->getKey()]);
    }
}
