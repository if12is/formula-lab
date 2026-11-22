<?php

namespace App\Http\Requests\Costing;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** What a finished good is published to Sanad with: SKU, barcode, selling unit and the selling, wholesale and guide prices. */
class PublishFinishedGoodRequest extends FormRequest
{
    use TranslatesValidationMessages;

    private const PRICE = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'uom' => ['required', 'string', 'max:50'],
            'sell_price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'wholesale_price' => self::PRICE,
            'guide_price' => self::PRICE,
            'name_en' => ['nullable', 'string', 'max:'.config('formula.max_text_length')],
        ];
    }

    /**
     * @return array{sku: string, barcode: string|null, uom: string, sell_price: string, wholesale_price: string|null, guide_price: string|null, name_en: string|null}
     */
    public function details(): array
    {
        $data = $this->validated();
        $text = fn (string $key): ?string => ($data[$key] ?? '') === '' ? null : trim($data[$key]);

        return [
            'sku' => trim($data['sku']),
            'barcode' => $text('barcode'),
            'uom' => trim($data['uom']),
            'sell_price' => $data['sell_price'],
            'wholesale_price' => $text('wholesale_price'),
            'guide_price' => $text('guide_price'),
            'name_en' => $text('name_en'),
        ];
    }
}
