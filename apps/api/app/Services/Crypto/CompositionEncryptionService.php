<?php

namespace App\Services\Crypto;

use App\Actions\Crypto\EncryptTenantCompositionsAction;
use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Tenant;
use App\Support\DTOs\CompositionEncryptionReport;

/** Encrypts the composition of formula versions that were saved before U-02, tenant by tenant. */
final class CompositionEncryptionService
{
    public function __construct(private EncryptTenantCompositionsAction $encryptTenant) {}

    /**
     * @param  string|null  $tenantId  Only this tenant; every tenant when null.
     *
     * @throws CompositionCryptoException When the tenant does not exist or no master key is configured.
     */
    public function run(bool $dryRun, ?string $tenantId = null): CompositionEncryptionReport
    {
        $tenants = Tenant::query()->when($tenantId !== null, fn ($query) => $query->whereKey($tenantId))->orderBy('id')->get();
        if ($tenantId !== null && $tenants->isEmpty()) {
            throw CompositionCryptoException::tenantUnknown($tenantId);
        }

        $report = new CompositionEncryptionReport($dryRun);
        foreach ($tenants as $tenant) {
            $report = $report->merge(($this->encryptTenant)($tenant, $dryRun));
        }

        return $report;
    }
}
