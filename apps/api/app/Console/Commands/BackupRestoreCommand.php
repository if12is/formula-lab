<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsRestore;
use App\Exceptions\Domain\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupRestoreCommand extends Command
{
    use ReportsRestore;

    protected $signature = 'backup:restore {file? : Backup file name or path (default: the newest)} {--into= : Name of the NEW scratch database to restore into}';

    protected $description = 'Trial restore into a new scratch database (never the live one) and compare the key-table row counts with the manifest.';

    public function handle(BackupService $service): int
    {
        $into = (string) $this->option('into');
        if ($into === '') {
            $this->components->error(__('backup.command.into_required'));

            return self::FAILURE;
        }

        try {
            $report = $service->restore($this->argument('file'), $into);
        } catch (BackupException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->printRestoreReport($report);
    }
}
