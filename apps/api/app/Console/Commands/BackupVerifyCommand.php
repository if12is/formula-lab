<?php

namespace App\Console\Commands;

use App\Exceptions\Domain\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {file? : Backup file name or path (default: the newest)}';

    protected $description = 'Check a backup without restoring it: manifest MAC, SHA-256 and a full authenticated decryption.';

    public function handle(BackupService $service): int
    {
        try {
            $manifest = $service->verify($this->argument('file'));
        } catch (BackupException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(__('backup.command.verified', ['file' => $manifest->file]));
        $this->line(__('backup.command.sha256', ['hash' => $manifest->sha256]));

        return self::SUCCESS;
    }
}
