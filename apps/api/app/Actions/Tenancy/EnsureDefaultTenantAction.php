<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;

/** The tenant that owns new formulas until real tenants exist (U-04). Created on first use. */
final class EnsureDefaultTenantAction
{
    public function __invoke(): Tenant
    {
        return Tenant::query()->firstOrCreate(['name' => config('formula.default_tenant_name')]);
    }
}
