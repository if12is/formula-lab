<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Lifecycle of an outbox item: pending until Sanad acks it (contract 3.2). */
enum SanadOutboxStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Conflict = 'conflict';
}
