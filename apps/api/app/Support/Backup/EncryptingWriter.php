<?php

namespace App\Support\Backup;

/** Push side of the backup cipher: buffers plaintext, writes one authenticated secretstream chunk per full block and a final-tagged chunk on finish(). */
final class EncryptingWriter
{
    private string $buffer = '';

    private int $plainBytes = 0;

    private int $encryptedBytes = 0;

    private bool $finished = false;

    /** @param  resource  $out */
    public function __construct(private $out, private string $state, string $header)
    {
        $this->write(BackupCipher::MAGIC.$header);
    }

    public function push(string $data): void
    {
        $this->buffer .= $data;
        $this->plainBytes += strlen($data);

        // Strictly more than one block is required, so the final chunk is never empty unless the whole input is.
        while (strlen($this->buffer) > BackupCipher::CHUNK_BYTES) {
            $this->seal(substr($this->buffer, 0, BackupCipher::CHUNK_BYTES), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, BackupCipher::CHUNK_BYTES);
        }
    }

    /** Writes the final chunk. Returns [plain bytes, encrypted bytes]. @return array{0: int, 1: int} */
    public function finish(): array
    {
        if (! $this->finished) {
            $this->seal($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->buffer = '';
            $this->finished = true;
            sodium_memzero($this->state);
        }

        return [$this->plainBytes, $this->encryptedBytes];
    }

    private function seal(string $plain, int $tag): void
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        $this->write(pack('N', strlen($cipher)).$cipher);
    }

    private function write(string $bytes): void
    {
        fwrite($this->out, $bytes);
        $this->encryptedBytes += strlen($bytes);
    }
}
