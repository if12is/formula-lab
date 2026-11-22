<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Whether a person has checked a registry row against its source: pending until then. */
enum DataVerification: string
{
    use HasOptions;

    case Pending = 'pending';
    case Verified = 'verified';

    public function label(): string
    {
        return __("families.data_verification.{$this->value}");
    }
}
