<?php

namespace App\Services\Crypto;

use App\Actions\Crypto\EnsureTenantDataKeyAction;
use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Tenant;
use App\Support\Crypto\KeyWrapper;
use Illuminate\Support\Facades\DB;

/** Hands the cipher a tenant's unwrapped data key (cached for the process) and finds the tenant a formula version belongs to. */
final class TenantKeyring
{
    /** @var array<string, string> */
    private array $dataKeys = [];

    /** @var array<string, string> */
    private array $versionTenants = [];

    public function __construct(
        private EnsureTenantDataKeyAction $ensureDataKey,
        private KeyWrapper $wrapper,
    ) {}

    /**
     * @return string Raw 32-byte data key; never leaves the process.
     *
     * @throws CompositionCryptoException
     */
    public function dataKey(string $tenantId): string
    {
        return $this->dataKeys[$tenantId] ??= $this->wrapper->unwrap(($this->ensureDataKey)($tenantId), $tenantId);
    }

    /** The data key of a tenant that already has one; null when it has none yet (a dry run must not create keys). */
    public function existingDataKey(Tenant $tenant): ?string
    {
        $wrapped = $tenant->getRawOriginal('wrapped_data_key');

        return is_string($wrapped) && $wrapped !== '' ? $this->dataKey((string) $tenant->getKey()) : null;
    }

    /** @throws CompositionCryptoException When the version does not exist. */
    public function tenantIdForVersion(string $versionId): string
    {
        if (isset($this->versionTenants[$versionId])) {
            return $this->versionTenants[$versionId];
        }
        $tenantId = DB::table('formula_versions')->join('formulas', 'formulas.id', '=', 'formula_versions.formula_id')
            ->where('formula_versions.id', $versionId)->value('formulas.tenant_id');
        if (! is_string($tenantId)) {
            throw CompositionCryptoException::tenantUnknown($versionId);
        }

        return $this->versionTenants[$versionId] = $tenantId;
    }

    /** Forget every cached key (after a re-wrap, or when the master key configuration changes). */
    public function flush(): void
    {
        $this->dataKeys = [];
        $this->versionTenants = [];
    }
}
