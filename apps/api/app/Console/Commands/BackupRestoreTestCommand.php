<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsRestore;
use App\Exceptions\Domain\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupRestoreTestCommand extends Command
{
    use ReportsRestore;

    protected $signature = 'backup:restore-test';

    protected $description = 'Monthly check: restore the newest backup into a throwaway database, compare row counts with the manifest, drop the database.';

    public function handle(BackupService $service): int
    {
        try {
            $report = $service->restoreTest();
        } catch (BackupException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->printRestoreReport($report);
    }
}
