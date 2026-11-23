<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** U-07: a group of fields a role may or may not receive from the server (config/field_visibility.php). */
enum FieldGroup: string
{
    use HasOptions;

    case Cost = 'cost';
    case Price = 'price';
    case Percentage = 'percentage';
}
