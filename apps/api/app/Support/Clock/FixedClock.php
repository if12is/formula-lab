<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/** A clock that says one chosen moment, for tests. */
final class FixedClock implements Clock
{
    public function __construct(private CarbonImmutable $moment) {}

    public function now(): CarbonImmutable
    {
        return $this->moment;
    }

    public function advanceTo(CarbonImmutable $moment): void
    {
        $this->moment = $moment;
    }
}
