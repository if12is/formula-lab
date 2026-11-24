<?php

namespace App\Actions\Backup;

use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStore;
use App\Support\DTOs\BackupManifest;

/** Checks a backup without restoring it: the manifest's MAC, the file's SHA-256 against it, and a full authenticated decryption. */
final class VerifyBackupAction
{
    public function __construct(
        private readonly BackupCipher $cipher,
        private readonly BackupStore $store,
    ) {}

    /** @throws BackupException */
    public function __invoke(string $path): BackupManifest
    {
        if (! is_file($path)) {
            throw BackupException::fileMissing($path);
        }
        if (! is_file($this->store->manifestPath($path))) {
            throw BackupException::manifestMissing(basename($path));
        }

        $manifest = $this->store->readManifest($path);
        $mac = $this->store->readMac($path);
        if ($manifest === null || $mac === null || ! hash_equals($this->cipher->mac($manifest->signedText()), $mac)) {
            throw BackupException::manifestInvalid(basename($path));
        }

        if (! hash_equals($manifest->sha256, (string) hash_file('sha256', $path))) {
            throw BackupException::hashMismatch(basename($path));
        }

        $handle = fopen($path, 'rb');
        try {
            $plainBytes = $this->cipher->decrypt($handle, static function (string $plain): void {});
        } finally {
            fclose($handle);
        }

        if ($plainBytes !== $manifest->plainBytes) {
            throw BackupException::manifestInvalid(basename($path));
        }

        return $manifest;
    }
}
