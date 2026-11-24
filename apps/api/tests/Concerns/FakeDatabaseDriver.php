<?php

namespace Tests\Concerns;

use App\Exceptions\Domain\BackupException;
use App\Support\Backup\DatabaseDriver;
use App\Support\Clock\FixedClock;
use Carbon\CarbonImmutable;

/** Stands in for pg_dump / pg_restore: "dumps" fixed bytes and keeps the databases it was asked to create in memory. */
final class FakeDatabaseDriver implements DatabaseDriver
{
    public string $dumpBytes;

    /** @var array<string, int> */
    public array $counts = ['formulas' => 3, 'audit_log' => 10];

    /** @var array<string, int> What a restored database reports; null means the same as. */
    public ?array $restoredCounts = null;

    /** @var list<string> */
    public array $databases = [];

    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $dropped = [];

    /** @var list<string> */
    public array $restoredBytes = [];

    public bool $failDump = false;

    public bool $failRestore = false;

    public ?FixedClock $advance = null;

    public ?CarbonImmutable $advanceTo = null;

    public function __construct()
    {
        $this->dumpBytes = random_bytes(150000);
    }

    public function dump(string $database, array $tables, callable $sink): array
    {
        if ($this->failDump) {
            throw BackupException::toolFailed('pg_dump', 'connection refused');
        }
        foreach (str_split($this->dumpBytes, 40000) as $chunk) {
            $sink($chunk);
        }
        $this->advance?->advanceTo($this->advanceTo);

        return array_intersect_key($this->counts, array_flip($tables));
    }

    public function restore(string $database, string $dumpPath): void
    {
        if ($this->failRestore) {
            throw BackupException::toolFailed('pg_restore', 'syntax error');
        }
        $this->restoredBytes[] = (string) file_get_contents($dumpPath);
    }

    public function databaseExists(string $name): bool
    {
        return in_array($name, $this->databases, true);
    }

    public function createDatabase(string $name): void
    {
        $this->databases[] = $name;
        $this->created[] = $name;
    }

    public function dropDatabase(string $name): void
    {
        $this->databases = array_values(array_diff($this->databases, [$name]));
        $this->dropped[] = $name;
    }

    public function countRows(string $database, array $tables): array
    {
        return array_intersect_key($this->restoredCounts ?? $this->counts, array_flip($tables));
    }
}
