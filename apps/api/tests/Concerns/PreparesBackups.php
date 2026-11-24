<?php

namespace Tests\Concerns;

use App\Support\Backup\DatabaseDriver;
use Illuminate\Filesystem\Filesystem;

/** A fresh temp backup directory, a fixed test key and a fake pg_dump runner for backup tests. */
trait PreparesBackups
{
    protected string $backupDirectory;

    protected function prepareBackups(): FakeDatabaseDriver
    {
        $this->backupDirectory = sys_get_temp_dir().'/fl-backup-test-'.bin2hex(random_bytes(6));
        config([
            'backup.path' => $this->backupDirectory,
            'backup.encryption_key' => base64_encode(str_repeat('k', 32)),
            'backup.retention_days' => 30,
            'backup.key_tables' => ['formulas', 'audit_log'],
        ]);

        $driver = new FakeDatabaseDriver;
        $this->app->instance(DatabaseDriver::class, $driver);

        return $driver;
    }

    protected function cleanBackups(): void
    {
        (new Filesystem)->deleteDirectory($this->backupDirectory);
    }

    /** Flips one byte in the middle of the file. */
    protected function flipByte(string $path): void
    {
        $bytes = file_get_contents($path);
        $middle = intdiv(strlen($bytes), 2);
        $bytes[$middle] = chr(ord($bytes[$middle]) ^ 0x01);
        file_put_contents($path, $bytes);
    }
}
