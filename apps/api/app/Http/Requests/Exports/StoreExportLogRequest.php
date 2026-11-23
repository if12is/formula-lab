<?php

namespace App\Http\Requests\Exports;

use App\Enums\ExportKind;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which printed document to log: the kind, and the record it was made from (a version, an order, or a lot for the trace). */
class StoreExportLogRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $trace = ExportKind::LotTrace->value;

        return [
            'kind' => ['required', Rule::enum(ExportKind::class)],
            'subject_id' => ['required_unless:kind,'.$trace, 'nullable', 'uuid'],
            'lot' => ['required_if:kind,'.$trace, 'nullable', 'string', 'max:255'],
            'material_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array{kind: ExportKind, subject_id: string|null, lot: string|null, material_id: string|null} */
    public function logData(): array
    {
        $data = $this->validated();

        return [
            'kind' => ExportKind::from($data['kind']),
            'subject_id' => $data['subject_id'] ?? null,
            'lot' => $data['lot'] ?? null,
            'material_id' => $data['material_id'] ?? null,
        ];
    }
}
