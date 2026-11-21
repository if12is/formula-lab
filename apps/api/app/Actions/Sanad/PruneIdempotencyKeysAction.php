<?php

namespace App\Actions\Sanad;

use Illuminate\Support\Facades\DB;

/** Deletes idempotency keys older than the retention window (contract section 6: at least 7 days). */
final class PruneIdempotencyKeysAction
{
    public function __invoke(): int
    {
        return DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subDays(config('sanad.idempotency.retention_days')))
            ->delete();
    }
}
