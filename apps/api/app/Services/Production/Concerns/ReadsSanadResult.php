<?php

namespace App\Services\Production\Concerns;

use App\Models\SanadOutbox;

/** Reads what Sanad answered (the ack `result`) for a production order's outbox row. */
trait ReadsSanadResult
{
    protected function productBatchUuid(?SanadOutbox $outbox): ?string
    {
        $uuid = $outbox?->result['product_batch_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }
}
