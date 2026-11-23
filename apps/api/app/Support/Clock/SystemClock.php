<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/** The server clock, in UTC. Honours Carbon::setTestNow, so tests may also freeze time without binding a fake. */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
