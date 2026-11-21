<?php

namespace App\Http\Requests\Material\Concerns;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\DecimalCompare;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The rules shared by adding and changing one component of a material's composition. */
trait ValidatesMaterialComposition
{
    use TranslatesValidationMessages;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // CAS numbers are free text on purpose: a test or an internal code is as valid as a registry number.
            'cas' => ['nullable', 'string', 'max:50', 'regex:/^\S+$/'],
            'inci' => ['nullable', 'string', 'max:255'],
            'pct_ww' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,12})?$/'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['cas', 'inci', 'pct_ww'])) {
                return;
            }

            if (trim((string) $this->input('cas')) === '' && trim((string) $this->input('inci')) === '') {
                $validator->errors()->add('cas', __('materials.composition.errors.identifier_required'));
            }

            if (DecimalCompare::compare((string) $this->input('pct_ww'), '100') > 0) {
                $validator->errors()->add('pct_ww', __('materials.composition.errors.pct_range'));
            }
        });
    }

    /** @return array<string, mixed> */
    public function componentData(): array
    {
        return $this->safe()->only(['cas', 'inci', 'pct_ww', 'source_id', 'confidence', 'valid_from']);
    }
}
