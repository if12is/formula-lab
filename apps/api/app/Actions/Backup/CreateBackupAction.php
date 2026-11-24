<?php

namespace App\Actions\Backup;

use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStore;
use App\Support\Backup\DatabaseDriver;
use App\Support\Clock\Clock;
use App\Support\DTOs\BackupManifest;

/** Dumps the database through the cipher into one encrypted file and writes its SHA-256 manifest beside it. A failed run leaves no partial file. */
final class CreateBackupAction
{
    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseDriver $driver,
        private readonly BackupCipher $cipher,
        private readonly BackupStore $store,
    ) {}

    /** @throws BackupException */
    public function __invoke(?string $database = null): BackupManifest
    {
        $database ??= (string) config('database.connections.'.config('database.default').'.database');
        $createdAt = $this->clock->now();
        $fileName = 'fl-backup-'.$createdAt->format('Ymd\THis\Z').BackupStore::EXTENSION;

        $directory = $this->store->ensureDirectory();
        $partial = tempnam($directory, '.partial-');
        $handle = fopen($partial, 'wb');
        chmod($partial, 0600);

        try {
            $writer = $this->cipher->writer($handle);
            $counts = $this->driver->dump($database, array_values((array) config('backup.key_tables')), $writer->push(...));
            [$plainBytes, $encryptedBytes] = $writer->finish();
            fclose($handle);

            $manifest = new BackupManifest($fileName, $createdAt->toIso8601ZuluString(), $database, hash_file('sha256', $partial), $encryptedBytes, $plainBytes, $counts);
            $path = $this->store->pathFor($fileName);
            rename($partial, $path);
        } catch (\Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($partial);
            throw $exception;
        }

        $manifestPath = $this->store->manifestPath($path);
        file_put_contents($manifestPath, json_encode(
            ['manifest' => $manifest->toArray(), 'mac' => $this->cipher->mac($manifest->signedText())],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        chmod($manifestPath, 0600);

        return $manifest;
    }
}
