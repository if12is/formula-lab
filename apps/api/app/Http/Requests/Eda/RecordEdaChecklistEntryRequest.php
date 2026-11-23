<?php

namespace App\Http\Requests\Eda;

use App\Enums\EdaItemStatus;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** One item of a product's EDA file: its status, with the document (title, optional reference and file) for `attached` and the reason for `not_applicable`. */
class RecordEdaChecklistEntryRequest extends FormRequest
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
            'status' => ['required', Rule::enum(EdaItemStatus::class)],
            'document_title' => ['nullable', 'string', 'max:200'],
            'document_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('eda.file.mimes')), 'max:'.(int) config('eda.file.max_kb')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = $this->input('status');
            if ($status === EdaItemStatus::Attached->value && trim((string) $this->input('document_title')) === '') {
                $validator->errors()->add('document_title', __('eda.validation.document_title_required'));
            }
            if ($status === EdaItemStatus::NotApplicable->value && trim((string) $this->input('note')) === '') {
                $validator->errors()->add('note', __('eda.validation.note_required'));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('eda.attributes')];
    }
}
