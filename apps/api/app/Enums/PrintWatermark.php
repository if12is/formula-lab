<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What a printed page is stamped with: a copy of an approved version, of a version that is not approved yet, or of a document with no version. */
enum PrintWatermark: string
{
    use HasOptions;

    case Approved = 'approved';
    case Unapproved = 'unapproved';
    case Neutral = 'neutral';
}
