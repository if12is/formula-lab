<?php

namespace App\Enums\Assistant;

use App\Enums\Concerns\HasOptions;

enum AssistantDisclosureOutcome: string
{
    use HasOptions;

    /** The data went to the provider. */
    case Sent = 'sent';

    /** There was no consent, so nothing was sent. */
    case Refused = 'refused';
}
