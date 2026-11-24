<?php

namespace App\Actions\Backup;

use App\Exceptions\Domain\BackupException;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStore;
use App\Support\Backup\DatabaseDriver;
use App\Support\DTOs\RestoreReport;

/**
 * Trial restore of a backup into a NEW scratch database. It refuses the live database (and every configured one), refuses a database that already
 * exists, verifies the manifest hash and decrypts the file before creating anything, and reports the key-table row counts against the manifest.
 */
final class RestoreBackupAction
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    public function __construct(
        private readonly VerifyBackupAction $verify,
        private readonly DatabaseDriver $driver,
        private readonly BackupCipher $cipher,
        private readonly BackupStore $store,
    ) {}

    /** @throws BackupException */
    public function __invoke(string $path, string $into): RestoreReport
    {
        $this->guard($into);

        $manifest = ($this->verify)($path);
        $this->guard($into, $manifest->database);

        if ($this->driver->databaseExists($into)) {
            throw BackupException::targetExists($into);
        }

        $plain = tempnam($this->store->ensureDirectory(), '.restore-');
        chmod($plain, 0600);

        try {
            $this->decryptTo($path, $plain);

            $this->driver->createDatabase($into);
            try {
                $this->driver->restore($into, $plain);
                $counts = $this->driver->countRows($into, array_keys($manifest->tables));
            } catch (\Throwable $exception) {
                $this->driver->dropDatabase($into);
                throw $exception;
            }
        } finally {
            @unlink($plain);
        }

        return RestoreReport::compare($manifest->file, $into, $manifest->tables, $counts);
    }

    /** @throws BackupException */
    private function guard(string $into, ?string $source = null): void
    {
        $protected = array_map(
            fn (mixed $connection): string => strtolower((string) ($connection['database'] ?? '')),
            array_values((array) config('database.connections')),
        );
        $protected[] = strtolower((string) $source);

        if (in_array(strtolower($into), array_filter($protected), true)) {
            throw BackupException::liveDatabaseGuard($into);
        }

        if (preg_match(self::NAME_PATTERN, $into) !== 1) {
            throw BackupException::invalidDatabaseName($into);
        }
    }

    private function decryptTo(string $path, string $plainPath): void
    {
        $in = fopen($path, 'rb');
        $out = fopen($plainPath, 'wb');
        try {
            $this->cipher->decrypt($in, static fn (string $plain): int|false => fwrite($out, $plain));
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
