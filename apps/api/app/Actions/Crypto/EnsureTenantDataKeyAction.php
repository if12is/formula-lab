<?php

namespace App\Actions\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Tenant;
use App\Support\Crypto\Aead;
use App\Support\Crypto\KeyWrapper;

/** Gives a tenant its data key on first use (random, wrapped by the master key); a tenant that has one keeps it. */
final class EnsureTenantDataKeyAction
{
    public function __construct(private KeyWrapper $wrapper) {}

    /**
     * @return string The wrapped data key as stored.
     *
     * @throws CompositionCryptoException When the tenant does not exist or no master key is configured.
     */
    public function __invoke(string $tenantId): string
    {
        $existing = Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            throw CompositionCryptoException::tenantUnknown($tenantId);
        }

        // Two writers may race: only the first update lands (whereNull), and both then read the winner's key.
        Tenant::query()->whereKey($tenantId)->whereNull('wrapped_data_key')
            ->update(['wrapped_data_key' => $this->wrapper->wrap(Aead::generateKey(), $tenantId)]);

        return (string) Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');
    }
}
