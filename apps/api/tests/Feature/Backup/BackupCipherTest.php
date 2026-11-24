<?php

namespace Tests\Feature\Backup;

use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupCipher;
use Tests\TestCase;

/** U-05: authenticated encryption of the backup stream. */
class BackupCipherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['backup.encryption_key' => base64_encode(str_repeat('a', 32))]);
    }

    private function encrypt(string $plain, int $piece = 7000): string
    {
        $stream = fopen('php://temp', 'w+b');
        $writer = (new BackupCipher)->writer($stream);
        foreach (str_split($plain, $piece) as $part) {
            $writer->push($part);
        }
        $writer->finish();
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function decrypt(string $cipherText): string
    {
        $in = fopen('php://temp', 'w+b');
        fwrite($in, $cipherText);
        rewind($in);
        $out = '';
        (new BackupCipher)->decrypt($in, function (string $plain) use (&$out): void {
            $out .= $plain;
        });

        return $out;
    }

    public function test_round_trip_of_small_empty_and_multi_chunk_input(): void
    {
        foreach ([0, 1, BackupCipher::CHUNK_BYTES, BackupCipher::CHUNK_BYTES + 1, 3 * BackupCipher::CHUNK_BYTES + 123] as $size) {
            $plain = $size === 0 ? '' : random_bytes($size);
            $this->assertSame($plain, $this->decrypt($this->encrypt($plain)), "size {$size}");
        }
    }

    public function test_the_ciphertext_does_not_contain_the_plaintext(): void
    {
        $plain = str_repeat('SECRET-FORMULA-', 1000);

        $this->assertStringNotContainsString('SECRET-FORMULA', $this->encrypt($plain));
    }

    public function test_a_changed_byte_is_detected(): void
    {
        $cipherText = $this->encrypt(random_bytes(200000));
        $cipherText[strlen($cipherText) - 40000] = chr(ord($cipherText[strlen($cipherText) - 40000]) ^ 1);

        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt($cipherText), __('exceptions.backup_decryption_failed'));
    }

    public function test_a_truncated_file_is_detected_even_at_a_chunk_boundary(): void
    {
        $plain = random_bytes(3 * BackupCipher::CHUNK_BYTES);
        $cipherText = $this->encrypt($plain, BackupCipher::CHUNK_BYTES);
        $firstChunk = strlen(BackupCipher::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 4 + BackupCipher::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt(substr($cipherText, 0, $firstChunk)));
        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt(substr($cipherText, 0, -1)));
    }

    public function test_bytes_appended_after_the_final_chunk_are_detected(): void
    {
        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt($this->encrypt('abc').'extra'));
    }

    public function test_a_wrong_key_cannot_decrypt(): void
    {
        $cipherText = $this->encrypt('abc');
        config(['backup.encryption_key' => base64_encode(str_repeat('b', 32))]);

        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt($cipherText), __('exceptions.backup_decryption_failed'));
    }

    public function test_a_file_that_is_not_a_backup_is_refused(): void
    {
        $this->assertThrowsException(BackupException::class, fn () => $this->decrypt(str_repeat('not a backup ', 20)));
    }

    public function test_a_missing_key_raises_the_translated_domain_exception(): void
    {
        foreach ([null, '', '   '] as $missing) {
            config(['backup.encryption_key' => $missing]);

            $this->assertThrowsException(BackupException::class, fn () => $this->encrypt('abc'), __('exceptions.backup_key_missing'));
        }
    }

    public function test_a_key_of_the_wrong_length_or_encoding_is_refused_and_a_base64_prefix_is_accepted(): void
    {
        foreach ([base64_encode('short'), '!!!not-base64!!!'] as $invalid) {
            config(['backup.encryption_key' => $invalid]);
            $this->assertThrowsException(BackupException::class, fn () => $this->encrypt('abc'), __('exceptions.backup_key_invalid'));
        }

        config(['backup.encryption_key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->assertSame('abc', $this->decrypt($this->encrypt('abc')));
    }

    public function test_the_mac_depends_on_the_key_and_the_data(): void
    {
        $cipher = new BackupCipher;
        $mac = $cipher->mac('data');

        $this->assertSame($mac, $cipher->mac('data'));
        $this->assertNotSame($mac, $cipher->mac('data2'));
        config(['backup.encryption_key' => base64_encode(str_repeat('b', 32))]);
        $this->assertNotSame($mac, $cipher->mac('data'));
    }

    public function test_the_error_messages_exist_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('exceptions.backup_key_missing', __('exceptions.backup_key_missing'));
            $this->assertNotSame('exceptions.backup_decryption_failed', __('exceptions.backup_decryption_failed'));
        }
    }
}
