<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What a "tested on formula X" record is about: the dose a material was used at, or the required HLB it was used with (O-07). */
enum TestedValueKind: string
{
    use HasOptions;

    case Dose = 'dose';
    case RequiredHlb = 'required_hlb';
}
