<?php

namespace App\Console\Commands;

use App\Actions\Sanad\PruneIdempotencyKeysAction;
use Illuminate\Console\Command;

class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete idempotency keys older than the retention window';

    public function handle(PruneIdempotencyKeysAction $prune): int
    {
        $this->info(__('sanad.command.pruned', ['count' => $prune()]));

        return self::SUCCESS;
    }
}
