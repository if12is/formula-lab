<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AuditAction: string
{
    use HasOptions;

    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
