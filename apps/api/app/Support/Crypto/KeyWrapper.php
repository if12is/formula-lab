<?php

namespace App\Support\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;

/** Wraps a tenant's data key with the master key: "v1.<master key id>.<sealed>", bound to the tenant id so it cannot be moved to another tenant. */
final class KeyWrapper
{
    public const FORMAT = 'v1';

    public function __construct(private MasterKeys $masterKeys) {}

    public function wrap(string $dataKey, string $tenantId): string
    {
        $master = $this->masterKeys->current();

        return self::FORMAT.'.'.$master['id'].'.'.Aead::seal($dataKey, $master['key'], $this->aad($tenantId));
    }

    /** @throws CompositionCryptoException When the master key that wrapped it is not configured, or the wrapped key was altered or belongs to another tenant. */
    public function unwrap(string $wrapped, string $tenantId): string
    {
        [$format, $keyId, $sealed] = array_pad(explode('.', $wrapped, 3), 3, '');
        if ($format !== self::FORMAT || $keyId === '' || $sealed === '') {
            throw CompositionCryptoException::decryptFailed('tenants.wrapped_data_key');
        }
        $masterKey = $this->masterKeys->find($keyId);
        if ($masterKey === null) {
            $this->masterKeys->current();
            throw CompositionCryptoException::masterKeyUnknown($keyId);
        }
        $dataKey = Aead::open($sealed, $masterKey, $this->aad($tenantId));
        if ($dataKey === null) {
            throw CompositionCryptoException::decryptFailed('tenants.wrapped_data_key');
        }

        return $dataKey;
    }

    /** True when the wrapped key was made by a master key other than the current one (a rotation is pending for it). */
    public function needsRewrap(string $wrapped): bool
    {
        return (explode('.', $wrapped, 3)[1] ?? '') !== $this->masterKeys->current()['id'];
    }

    private function aad(string $tenantId): string
    {
        return 'formula-lab:v1:data-key:'.$tenantId;
    }
}
