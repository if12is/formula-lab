<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Operation of a Sanad push item; delete is a tombstone. */
enum SanadOp: string
{
    use HasOptions;

    case Upsert = 'upsert';
    case Delete = 'delete';
}
