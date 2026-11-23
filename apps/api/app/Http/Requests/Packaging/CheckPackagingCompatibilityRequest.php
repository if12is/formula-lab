<?php

namespace App\Http\Requests\Packaging;

use App\Enums\ItemKind;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The packaging components of a BOM to check for compatibility (H-02). */
class CheckPackagingCompatibilityRequest extends FormRequest
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
            'item_ids' => ['required', 'array', 'min:1', 'max:50'],
            'item_ids.*' => ['required', 'uuid', 'distinct', Rule::exists(TradeMaterial::class, 'id')->whereIn('item_kind', ItemKind::packagingValues())],
        ];
    }

    /** @return list<string> */
    public function itemIds(): array
    {
        return array_values($this->validated()['item_ids']);
    }
}
