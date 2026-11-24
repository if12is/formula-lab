<?php

namespace App\Exceptions\Domain;

/** Thrown when a formula's encrypted composition cannot be sealed or opened: no key, wrong key, tampered or unencrypted data. */
class CompositionCryptoException extends BusinessRuleViolationException
{
    public static function masterKeyMissing(): self
    {
        return new self(__('exceptions.crypto_master_key_missing'));
    }

    public static function masterKeyInvalid(): self
    {
        return new self(__('exceptions.crypto_master_key_invalid'));
    }

    public static function masterKeyUnknown(string $keyId): self
    {
        return new self(__('exceptions.crypto_master_key_unknown', ['key' => $keyId]));
    }

    public static function tenantUnknown(string $tenantId): self
    {
        return new self(__('exceptions.crypto_tenant_unknown', ['tenant' => $tenantId]));
    }

    public static function decryptFailed(string $context): self
    {
        return new self(__('exceptions.crypto_decrypt_failed', ['column' => $context]));
    }

    public static function notEncrypted(string $context): self
    {
        return new self(__('exceptions.crypto_not_encrypted', ['column' => $context]));
    }

    public static function verificationFailed(string $versionId): self
    {
        return new self(__('exceptions.crypto_verification_failed', ['version' => $versionId]));
    }
}
