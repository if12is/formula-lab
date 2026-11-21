<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Abilities of a Formula Lab device token. */
enum SanadAbility: string
{
    use HasOptions;

    case ChangesWrite = 'changes:write';
    case OutboxRead = 'outbox:read';
    case OutboxAck = 'outbox:ack';
}
