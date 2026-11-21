<?php

namespace App\Http\Requests\Compliance\Concerns;

use App\Http\Requests\Concerns\TranslatesValidationMessages;

/** The fields of a rule set's own page: its identity, wording and source (the rules are separate). */
trait ValidatesRuleSet
{
    use TranslatesValidationMessages;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'version' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'scope_notes' => ['nullable', 'string', 'max:2000'],
            'source_id' => ['nullable', 'uuid', 'exists:sources,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function ruleSetData(): array
    {
        return $this->validated();
    }
}
