<?php

namespace App\Actions\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Tenant;
use App\Support\Crypto\KeyWrapper;

/** Re-wraps one tenant's data key under the current master key. The data key itself, and so every encrypted row, is untouched. */
final class RewrapTenantKeyAction
{
    public function __construct(private KeyWrapper $wrapper) {}

    /**
     * @return bool True when the wrapped key was (or, in a dry run, would be) replaced; false when it is already on the current master key.
     *
     * @throws CompositionCryptoException When the old master key is not configured or the new wrapping does not open to the same data key.
     */
    public function __invoke(Tenant $tenant, bool $dryRun): bool
    {
        $wrapped = (string) $tenant->getRawOriginal('wrapped_data_key');
        $tenantId = (string) $tenant->getKey();
        $dataKey = $this->wrapper->unwrap($wrapped, $tenantId);
        if (! $this->wrapper->needsRewrap($wrapped)) {
            return false;
        }

        $rewrapped = $this->wrapper->wrap($dataKey, $tenantId);
        if (! hash_equals($dataKey, $this->wrapper->unwrap($rewrapped, $tenantId))) {
            throw CompositionCryptoException::decryptFailed('tenants.wrapped_data_key');
        }
        if (! $dryRun) {
            Tenant::query()->whereKey($tenantId)->where('wrapped_data_key', $wrapped)->update(['wrapped_data_key' => $rewrapped]);
        }

        return true;
    }
}
