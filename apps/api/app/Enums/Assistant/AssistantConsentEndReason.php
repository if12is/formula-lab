<?php

namespace App\Enums\Assistant;

use App\Enums\Concerns\HasOptions;

/** Why a consent ended. */
enum AssistantConsentEndReason: string
{
    use HasOptions;

    /** The user took it back. */
    case User = 'user';

    /** The session ended (sign-out). */
    case SessionEnded = 'session_ended';

    /** The consent outlived the allowed time and the clock closed it. */
    case Expired = 'expired';
}
