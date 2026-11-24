<?php

namespace App\Support\Backup;

use App\Exceptions\Domain\BackupException;

/**
 * Authenticated encryption of a backup stream (libsodium secretstream, XChaCha20-Poly1305), key from config('backup.encryption_key').
 * File layout: MAGIC | 24-byte stream header | repeated [uint32 length | sealed chunk], the last chunk tagged FINAL.
 * A wrong key, a changed byte, a dropped chunk or a truncated tail all fail decryption; nothing partial is accepted.
 */
final class BackupCipher
{
    public const MAGIC = "FLBAK1\n";

    public const CHUNK_BYTES = 65536;

    private const MAX_SEALED = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    /** @param  resource  $out */
    public function writer($out): EncryptingWriter
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());

        return new EncryptingWriter($out, $state, $header);
    }

    /**
     * Decrypts the whole stream, handing each plaintext block to $sink. Returns the plaintext byte count.
     *
     * @param  resource  $in
     * @param  callable(string): void  $sink
     *
     * @throws BackupException
     */
    public function decrypt($in, callable $sink): int
    {
        $key = $this->key();
        $headerLength = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

        $prefix = $this->read($in, strlen(self::MAGIC) + $headerLength);
        if (! str_starts_with($prefix, self::MAGIC)) {
            throw BackupException::decryptionFailed();
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($prefix, strlen(self::MAGIC)), $key);
        $plainBytes = 0;

        while (true) {
            $length = unpack('N', $this->read($in, 4))[1];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::MAX_SEALED) {
                throw BackupException::decryptionFailed();
            }

            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($in, $length));
            if ($opened === false) {
                throw BackupException::decryptionFailed();
            }

            [$plain, $tag] = $opened;
            $sink($plain);
            $plainBytes += strlen($plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $trailing = fread($in, 1);
                if ($trailing !== '' && $trailing !== false) {
                    throw BackupException::decryptionFailed();
                }

                return $plainBytes;
            }
        }
    }

    /** Keyed MAC (HMAC-SHA256, separate subkey) that makes the manifest tamper-evident: the file hash alone could be rewritten together with the file. */
    public function mac(string $data): string
    {
        return hash_hmac('sha256', $data, hash_hmac('sha256', 'fl-backup-manifest-v1', $this->key(), true));
    }

    /** @throws BackupException */
    private function key(): string
    {
        $encoded = config('backup.encryption_key');
        if (! is_string($encoded) || trim($encoded) === '') {
            throw BackupException::keyMissing();
        }

        $raw = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw BackupException::keyInvalid();
        }

        return $raw;
    }

    /**
     * Reads exactly $length bytes or fails: a short read means the file was cut off.
     *
     * @param  resource  $in
     */
    private function read($in, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($in, $length - strlen($data));
            if ($part === false || $part === '') {
                throw BackupException::decryptionFailed();
            }
            $data .= $part;
        }

        return $data;
    }
}
