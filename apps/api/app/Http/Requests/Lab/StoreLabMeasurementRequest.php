<?php

namespace App\Http\Requests\Lab;

use App\Enums\LabProperty;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One measurement of a trial. The value is required and never defaults to zero; it is a plain decimal string. A measurement
 * names the protocol it followed or the method, and the instrument that took it.
 */
class StoreLabMeasurementRequest extends FormRequest
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
            'property' => ['required', 'string', Rule::enum(LabProperty::class)],
            'value' => ['required', 'string', 'regex:/^-?\d{1,18}(\.\d{1,12})?$/'],
            'unit' => ['required', 'string', 'max:20'],
            'lab_protocol_id' => ['nullable', 'uuid', 'exists:lab_protocols,id'],
            'method' => ['nullable', 'string', 'max:'.config('lab.max_text_length')],
            'instrument' => ['required', 'string', 'max:'.config('lab.max_text_length')],
            'measured_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'note' => ['nullable', 'string', 'max:'.config('lab.max_note_length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['value', 'method', 'instrument', 'note'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }

        // An empty field stays empty: it is validated as missing, never turned into a number.
        foreach (['method', 'lab_protocol_id', 'note'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $property = LabProperty::tryFrom((string) $this->input('property'));

            if ($property !== null && ! in_array($this->input('unit'), $property->units(), true)) {
                $validator->errors()->add('unit', __('lab.errors.unit_not_allowed', ['property' => $property->label()]));
            }

            if ($this->input('lab_protocol_id') === null && $this->input('method') === null) {
                $validator->errors()->add('method', __('lab.errors.method_or_protocol'));
            }
        });
    }

    /** @return array{property: string, value: string, unit: string, lab_protocol_id: string|null, method: string|null, instrument: string, measured_at: string, note: string|null} */
    public function measurementData(): array
    {
        return [
            'property' => $this->validated('property'),
            'value' => $this->validated('value'),
            'unit' => $this->validated('unit'),
            'lab_protocol_id' => $this->validated('lab_protocol_id'),
            'method' => $this->validated('method'),
            'instrument' => $this->validated('instrument'),
            'measured_at' => $this->validated('measured_at'),
            'note' => $this->validated('note'),
        ];
    }
}
