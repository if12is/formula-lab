<?php

namespace App\Support\Crypto;

/** XChaCha20-Poly1305 (libsodium, bundled with PHP): authenticated encryption with a random 24-byte nonce per message and bound context (AAD). */
final class Aead
{
    public const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    public static function generateKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /** @return string nonce || ciphertext || tag, URL-safe base64 without padding. */
    public static function seal(string $plaintext, string $key, string $aad): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);

        return sodium_bin2base64($nonce.$cipher, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /** Null when the key, the context or a single bit of the message is wrong. */
    public static function open(string $sealed, string $key, string $aad): ?string
    {
        try {
            $raw = sodium_base642bin($sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return null;
        }
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) < $nonceBytes + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceBytes), $aad, substr($raw, 0, $nonceBytes), $key);

        return $plain === false ? null : $plain;
    }
}
