<?php

namespace App\Support\Backup;

use App\Exceptions\Domain\BackupException;
use App\Support\DTOs\BackupManifest;

/** The backup directory on the local disk: where files live, which exist and what their manifests say. */
final class BackupStore
{
    public const EXTENSION = '.flbak';

    public const MANIFEST_SUFFIX = '.manifest.json';

    public function directory(): string
    {
        return rtrim((string) config('backup.path'), '/');
    }

    public function ensureDirectory(): string
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw BackupException::toolFailed('mkdir', $directory);
        }

        return $directory;
    }

    public function pathFor(string $fileName): string
    {
        return $this->directory().'/'.$fileName;
    }

    public function manifestPath(string $backupPath): string
    {
        return $backupPath.self::MANIFEST_SUFFIX;
    }

    /** @return list<string> Absolute paths of the backup files, newest first (the name carries the UTC time). */
    public function files(): array
    {
        $files = glob($this->directory().'/*'.self::EXTENSION) ?: [];
        rsort($files);

        return array_values($files);
    }

    /** A path, a file name inside the directory, or null for the newest backup. @throws BackupException */
    public function resolve(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            $files = $this->files();
            if ($files === []) {
                throw BackupException::noneFound($this->directory());
            }

            return $files[0];
        }

        foreach ([$reference, $this->pathFor($reference)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw BackupException::fileMissing($reference);
    }

    /** The manifest as written, without checking its MAC (that needs the key and is VerifyBackupAction's job). Null when unreadable. */
    public function readManifest(string $backupPath): ?BackupManifest
    {
        $text = @file_get_contents($this->manifestPath($backupPath));
        $decoded = $text === false ? null : json_decode($text, true);
        if (! is_array($decoded) || ! is_array($decoded['manifest'] ?? null) || ! is_string($decoded['mac'] ?? null)) {
            return null;
        }

        try {
            return BackupManifest::fromArray($decoded['manifest']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function readMac(string $backupPath): ?string
    {
        $decoded = json_decode((string) @file_get_contents($this->manifestPath($backupPath)), true);

        return is_array($decoded) && is_string($decoded['mac'] ?? null) ? $decoded['mac'] : null;
    }
}
