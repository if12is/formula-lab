<?php

namespace App\Enums\Production;

use App\Enums\Concerns\HasOptions;

/** draft -> approved -> sent (queued for Sanad). Approved and sent orders no longer change. */
enum ProductionOrderStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
}
