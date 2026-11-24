<?php

namespace App\Console\Commands;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Services\Crypto\CompositionEncryptionService;
use Illuminate\Console\Command;

class EncryptCompositionsCommand extends Command
{
    protected $signature = 'crypto:encrypt-compositions {--dry-run : Verify every version and report, without writing} {--tenant= : Only this tenant id}';

    protected $description = 'Encrypt the composition of formula versions saved before encryption at rest, verifying each version hash before and after';

    public function handle(CompositionEncryptionService $encryption): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant');
        try {
            $report = $encryption->run($dryRun, is_string($tenant) && $tenant !== '' ? $tenant : null);
        } catch (CompositionCryptoException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun && $this->warn(__('crypto.command.dry_run'));
        $this->info(__('crypto.command.encrypt_summary', [
            'scanned' => $report->versionsScanned,
            'pending' => $report->versionsPending,
            'encrypted' => $report->versionsEncrypted,
            'columns' => $report->columnsEncrypted,
            'already' => $report->columnsAlreadyEncrypted,
        ]));
        foreach ($report->failures as $failure) {
            $this->error(__('crypto.command.encrypt_failure', ['version' => $failure['version'], 'reason' => $failure['reason']]));
        }
        $report->succeeded() && ! $dryRun && $this->info(__('crypto.command.encrypt_ok'));

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
