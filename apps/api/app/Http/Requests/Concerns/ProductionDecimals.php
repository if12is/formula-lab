<?php

namespace App\Http\Requests\Concerns;

/** The shape of a decimal typed into a production form: a plain decimal string (never a float), at most 9 decimals. */
trait ProductionDecimals
{
    /** Non-negative decimal. */
    protected const DECIMAL = '/^\d{1,12}(\.\d{1,9})?$/';

    /** Positive decimal: not all zeros. */
    protected const NOT_ZERO = '/^0+(\.0+)?$/';

    /** Decimal that may be negative (a reading). */
    protected const SIGNED_DECIMAL = '/^-?\d{1,12}(\.\d{1,9})?$/';

    /** @return list<string> */
    protected function decimalRules(bool $required = true, bool $positive = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'regex:'.self::DECIMAL,
            ...($positive ? ['not_regex:'.self::NOT_ZERO] : []),
        ];
    }

    /** Trims every string input so a pasted value with spaces is the same value. */
    protected function trimStrings(string ...$keys): void
    {
        $this->merge(collect($keys)->mapWithKeys(fn (string $key): array => [$key => is_string($this->input($key)) ? trim($this->input($key)) : $this->input($key)])->all());
    }
}
