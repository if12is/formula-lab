<?php

namespace App\Enums\Assistant;

use App\Enums\Concerns\HasOptions;

enum AssistantRequestStatus: string
{
    use HasOptions;

    /** The reply passed the number guard. */
    case Answered = 'answered';

    /** Every attempt had a number no tool produced, so the safe fallback went out. */
    case Fallback = 'fallback';

    /** The provider could not be reached or answered with an error. */
    case Failed = 'failed';
}
