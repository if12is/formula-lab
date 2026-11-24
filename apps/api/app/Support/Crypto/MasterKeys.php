<?php

namespace App\Support\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;

/** The master key (and, during a rotation, the previous ones) read from config/crypto.php; identified by a keyed fingerprint, never by the key. */
final class MasterKeys
{
    /**
     * @return array{id: string, key: string}
     *
     * @throws CompositionCryptoException When no master key is configured or it is not 32 bytes.
     */
    public function current(): array
    {
        $configured = config('crypto.master_key');
        if (! is_string($configured) || trim($configured) === '') {
            throw CompositionCryptoException::masterKeyMissing();
        }

        return $this->parse($configured);
    }

    /** The key a wrapped key names, among the current and the previous master keys; null when it is not configured. */
    public function find(string $keyId): ?string
    {
        $configured = [config('crypto.master_key'), ...(array) config('crypto.previous_master_keys')];
        foreach ($configured as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $parsed = $this->parse($candidate);
            if (hash_equals($parsed['id'], $keyId)) {
                return $parsed['key'];
            }
        }

        return null;
    }

    /** @return array{id: string, key: string} */
    private function parse(string $configured): array
    {
        $configured = trim($configured);
        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : (ctype_xdigit($configured) && strlen($configured) === Aead::KEY_BYTES * 2 ? hex2bin($configured) : false);
        if (! is_string($key) || strlen($key) !== Aead::KEY_BYTES) {
            throw CompositionCryptoException::masterKeyInvalid();
        }

        return ['id' => substr(hash_hmac('sha256', 'formula-lab:master-key-id', $key), 0, 16), 'key' => $key];
    }
}
