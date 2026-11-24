<?php

namespace App\Console\Commands\Concerns;

use App\Support\DTOs\RestoreReport;

/** Prints the row counts of a restore against its manifest, and returns the command exit code. */
trait ReportsRestore
{
    private function printRestoreReport(RestoreReport $report): int
    {
        $this->components->info(__('backup.command.restored', ['file' => $report->file, 'database' => $report->database]));
        $this->table(
            [__('backup.command.table'), __('backup.command.expected'), __('backup.command.actual'), __('backup.command.result')],
            array_map(fn (array $row): array => [
                $row['table'], $row['expected'], $row['actual'] ?? '-', $row['matches'] ? __('backup.command.match') : __('backup.command.differs'),
            ], $report->tables),
        );

        if (! $report->allMatch()) {
            $this->components->error(__('exceptions.backup_counts_differ'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
