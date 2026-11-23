<?php

namespace App\Http\Requests\Concerns;

/** Rules for a decimal typed as text (a percentage, a price): digits with an optional fraction, never a float. */
trait DecimalRules
{
    /** @return list<string> */
    protected function decimal(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];
    }
}
