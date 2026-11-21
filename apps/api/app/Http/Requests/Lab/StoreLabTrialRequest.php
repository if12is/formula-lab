<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\FormulaVersion;
use Illuminate\Foundation\Http\FormRequest;

/** A new lab trial: the formula version, the batch (size, unit), the date and the operator. */
class StoreLabTrialRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'formula_version_id' => ['required', 'uuid', 'exists:formula_versions,id'],
            'batch_size' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,9})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'batch_unit' => ['required', 'string', 'in:'.implode(',', config('lab.batch_units'))],
            'trial_date' => ['required', 'date_format:Y-m-d'],
            'operator' => ['required', 'string', 'max:'.config('lab.max_text_length')],
            'notes' => ['nullable', 'string', 'max:'.config('lab.max_note_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'batch_size' => is_string($this->input('batch_size')) ? trim($this->input('batch_size')) : $this->input('batch_size'),
            'operator' => is_string($this->input('operator')) ? trim($this->input('operator')) : $this->input('operator'),
        ]);
    }

    public function formulaVersion(): FormulaVersion
    {
        return FormulaVersion::query()->findOrFail($this->validated('formula_version_id'));
    }

    /** @return array{batch_size: string, batch_unit: string, trial_date: string, operator: string, notes: string|null} */
    public function trialData(): array
    {
        return [
            'batch_size' => $this->validated('batch_size'),
            'batch_unit' => $this->validated('batch_unit'),
            'trial_date' => $this->validated('trial_date'),
            'operator' => $this->validated('operator'),
            'notes' => $this->validated('notes'),
        ];
    }
}
