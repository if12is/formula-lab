<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Where a price list stands with Sanad: never published (draft), waiting for Sanad to pull it, or Sanad's verdict on it. */
enum PriceListSyncStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Conflict = 'conflict';
}
