<?php

namespace App\Console\Commands;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Services\Crypto\TenantKeyRotationService;
use Illuminate\Console\Command;

class RewrapTenantKeysCommand extends Command
{
    protected $signature = 'crypto:rewrap-tenant-keys {--dry-run : Check every tenant key and report, without writing}';

    protected $description = 'Re-wrap every tenant data key under the current master key (master key rotation); encrypted rows are not touched';

    public function handle(TenantKeyRotationService $rotation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            $report = $rotation->rewrapAll($dryRun);
        } catch (CompositionCryptoException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun && $this->warn(__('crypto.command.dry_run'));
        $this->info(__('crypto.command.rewrap_summary', ['tenants' => $report->tenants, 'rewrapped' => $report->rewrapped, 'current' => $report->alreadyCurrent, 'without_key' => $report->withoutKey]));
        $dryRun || $this->line(__('crypto.command.rewrap_next'));

        return self::SUCCESS;
    }
}
