<?php

namespace App\Support\Backup;

use App\Exceptions\Domain\BackupException;

/** The PostgreSQL tools behind backups, as an interface so tests need no pg_dump binary. */
interface DatabaseDriver
{
    /**
     * Streams a custom-format dump of $database to $sink and returns the row count of each of $tables that exists, taken in the same snapshot as the dump.
     *
     * @param  list<string>  $tables
     * @param  callable(string): void  $sink
     * @return array<string, int>
     *
     * @throws BackupException
     */
    public function dump(string $database, array $tables, callable $sink): array;

    /**
     * Loads the dump file into the existing, empty database $database, all or nothing.
     *
     * @throws BackupException
     */
    public function restore(string $database, string $dumpPath): void;

    public function databaseExists(string $name): bool;

    /** @throws BackupException */
    public function createDatabase(string $name): void;

    public function dropDatabase(string $name): void;

    /**
     * @param  list<string>  $tables
     * @return array<string, int> Count per table that exists.
     */
    public function countRows(string $database, array $tables): array;
}
