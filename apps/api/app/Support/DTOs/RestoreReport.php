<?php

namespace App\Support\DTOs;

/** The outcome of a trial restore: row counts of the key tables in the restored database against the manifest. */
final readonly class RestoreReport
{
    /** @param  list<array{table: string, expected: int, actual: int|null, matches: bool}>  $tables */
    public function __construct(
        public string $file,
        public string $database,
        public array $tables,
    ) {}

    /** @param  array<string, int>  $expected  @param  array<string, int>  $actual */
    public static function compare(string $file, string $database, array $expected, array $actual): self
    {
        $rows = [];
        foreach ($expected as $table => $count) {
            $found = $actual[$table] ?? null;
            $rows[] = ['table' => $table, 'expected' => $count, 'actual' => $found, 'matches' => $found === $count];
        }

        return new self($file, $database, $rows);
    }

    public function allMatch(): bool
    {
        foreach ($this->tables as $row) {
            if (! $row['matches']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['file' => $this->file, 'database' => $this->database, 'all_match' => $this->allMatch(), 'tables' => $this->tables];
    }
}
