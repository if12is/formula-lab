<?php

namespace App\Support\Backup;

use App\Exceptions\Domain\BackupException;
use PDO;
use Symfony\Component\Process\Process;

/** pg_dump / pg_restore through Symfony Process, and CREATE/DROP DATABASE and row counts through a PDO of its own (never the app's transaction). */
final class PostgresDriver implements DatabaseDriver
{
    private const STDERR_TAIL_BYTES = 2000;

    public function dump(string $database, array $tables, callable $sink): array
    {
        // One repeatable-read transaction exports a snapshot: pg_dump reads that snapshot and the counts come from it, so they match the dump exactly.
        $pdo = $this->connect($database);
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');

        try {
            $snapshot = (string) $pdo->query('select pg_export_snapshot()')->fetchColumn();
            $counts = $this->count($pdo, $tables);

            $process = $this->process((string) config('backup.pg_dump_binary'), [
                '--format=custom', '--no-owner', '--no-acl', '--snapshot='.$snapshot, '--dbname='.$database,
            ]);
            $this->stream($process, 'pg_dump', $sink);

            return $counts;
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }

    public function restore(string $database, string $dumpPath): void
    {
        $process = $this->process((string) config('backup.pg_restore_binary'), [
            '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction', '--dbname='.$database, $dumpPath,
        ]);
        $this->stream($process, 'pg_restore', static function (string $chunk): void {});
    }

    public function databaseExists(string $name): bool
    {
        $statement = $this->connect($this->maintenanceDatabase())->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$name]);

        return $statement->fetchColumn() !== false;
    }

    public function createDatabase(string $name): void
    {
        $this->connect($this->maintenanceDatabase())->exec('create database '.$this->quote($name));
    }

    public function dropDatabase(string $name): void
    {
        $this->connect($this->maintenanceDatabase())->exec('drop database if exists '.$this->quote($name).' with (force)');
    }

    public function countRows(string $database, array $tables): array
    {
        return $this->count($this->connect($database), $tables);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function count(PDO $pdo, array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $exists = $pdo->prepare('select to_regclass(?) is not null');
            $exists->execute([$this->quote($table)]);
            if ($exists->fetchColumn()) {
                $counts[$table] = (int) $pdo->query('select count(*) from '.$this->quote($table))->fetchColumn();
            }
        }

        return $counts;
    }

    /** @param  list<string>  $arguments */
    private function process(string $binary, array $arguments): Process
    {
        $connection = $this->settings();

        $process = new Process(
            [$binary, '--host='.$connection['host'], '--port='.$connection['port'], '--username='.$connection['username'], ...$arguments],
            null,
            ['PGPASSWORD' => $connection['password']],
        );
        $process->setTimeout((float) config('backup.process_timeout_seconds'));

        return $process;
    }

    /**
     * Runs the process, feeding stdout to $sink as it arrives (never held in memory) and keeping the tail of stderr for the error.
     *
     * @param  callable(string): void  $sink
     */
    private function stream(Process $process, string $tool, callable $sink): void
    {
        $stderr = '';

        try {
            $process->start();
            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    $sink($data);
                } else {
                    $stderr = substr($stderr.$data, -self::STDERR_TAIL_BYTES);
                }
            }
        } catch (BackupException $exception) {
            $process->stop(0);
            throw $exception;
        } catch (\Throwable $exception) {
            $process->stop(0);
            throw BackupException::toolFailed($tool, $exception->getMessage());
        }

        if ($process->getExitCode() !== 0) {
            throw BackupException::toolFailed($tool, trim($stderr) ?: 'exit '.$process->getExitCode());
        }
    }

    private function connect(string $database): PDO
    {
        $connection = $this->settings();

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $database),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function maintenanceDatabase(): string
    {
        return (string) config('backup.maintenance_database');
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** @return array{host: string, port: string, username: string, password: string} */
    private function settings(): array
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        return [
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '5432'),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
        ];
    }
}
