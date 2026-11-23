<?php

namespace App\Exceptions\Domain;

use App\Enums\FieldGroup;

/** Thrown when a role asks for a screen that exists only to show a field group it may not see (U-07). */
class FieldGroupForbiddenException extends BusinessRuleViolationException
{
    public static function forGroup(FieldGroup $group): self
    {
        return new self(__('exceptions.field_group_forbidden', ['group' => $group->label()]));
    }
}
