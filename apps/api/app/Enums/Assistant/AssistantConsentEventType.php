<?php

namespace App\Enums\Assistant;

use App\Enums\Concerns\HasOptions;

enum AssistantConsentEventType: string
{
    use HasOptions;

    case Granted = 'granted';
    case Revoked = 'revoked';
}
