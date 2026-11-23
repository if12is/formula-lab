<?php

namespace App\Http\Requests\Coa;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** A certificate of analysis to read: the file (type and size limits from config coa.file) and the material it belongs to. */
class StoreCoaDocumentRequest extends FormRequest
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
            'trade_material_id' => ['required', 'uuid', 'exists:trade_materials,id'],
            'file' => ['required', 'file', 'mimes:'.implode(',', (array) config('coa.file.mimes')), 'max:'.(int) config('coa.file.max_kb')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...(array) __('validation.attributes'), ...(array) __('coa.attributes')];
    }
}
