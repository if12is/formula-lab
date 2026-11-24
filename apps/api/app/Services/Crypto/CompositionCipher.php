<?php

namespace App\Services\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Support\Crypto\Aead;

/**
 * Seals and opens one composition value for a tenant. The stored form is "enc:v1:<sealed>"; the tenant id and the column
 * ("formula_lines.percent_ww") are authenticated with it, so a value copied to another column or another tenant fails to open.
 */
final class CompositionCipher
{
    public const PREFIX = 'enc:v1:';

    public function __construct(private TenantKeyring $keyring) {}

    public static function context(string $table, string $column): string
    {
        return $table.'.'.$column;
    }

    public static function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** @throws CompositionCryptoException When there is no usable master key or the tenant is unknown. */
    public function seal(string $plaintext, string $tenantId, string $context): string
    {
        return self::PREFIX.Aead::seal($plaintext, $this->keyring->dataKey($tenantId), $this->aad($tenantId, $context));
    }

    /** @throws CompositionCryptoException When the value was altered, belongs to another tenant or column, or the key is missing. */
    public function open(string $stored, string $tenantId, string $context): string
    {
        if (! self::isSealed($stored)) {
            throw CompositionCryptoException::notEncrypted($context);
        }
        $plain = Aead::open(substr($stored, strlen(self::PREFIX)), $this->keyring->dataKey($tenantId), $this->aad($tenantId, $context));
        if ($plain === null) {
            throw CompositionCryptoException::decryptFailed($context);
        }

        return $plain;
    }

    private function aad(string $tenantId, string $context): string
    {
        return 'formula-lab:v1:'.$tenantId.':'.$context;
    }
}
