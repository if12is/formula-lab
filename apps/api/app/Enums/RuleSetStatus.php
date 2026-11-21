<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** A rule set is edited while it is a draft; publishing freezes it (and its hash) for good. */
enum RuleSetStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Published = 'published';
}
