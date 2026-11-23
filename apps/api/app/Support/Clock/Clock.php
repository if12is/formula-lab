<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

/** The one source of "now" for records that must carry a trustworthy time (terms acceptance, export log). Tests bind a fixed clock. */
interface Clock
{
    public function now(): CarbonImmutable;
}
