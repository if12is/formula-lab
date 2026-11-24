<?php

namespace App\Console\Commands;

use App\Exceptions\Domain\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Write an encrypted PostgreSQL backup with its SHA-256 manifest, then prune backups older than the retention.';

    public function handle(BackupService $service): int
    {
        try {
            $result = $service->run();
        } catch (BackupException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];
        $this->components->info(__('backup.command.created', ['file' => $manifest->file, 'bytes' => $manifest->encryptedBytes]));
        $this->line(__('backup.command.sha256', ['hash' => $manifest->sha256]));
        $this->line(__('backup.command.pruned', ['count' => $result['pruned']]));

        return self::SUCCESS;
    }
}
