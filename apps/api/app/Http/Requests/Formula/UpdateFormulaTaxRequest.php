<?php

namespace App\Http\Requests\Formula;

use App\Enums\TaxMode;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Services\Tax\TaxRegimeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFormulaTaxRequest extends FormRequest
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
            // One of the options the screen offers: "disabled", or "rate:<allowed rate>" (config/tax.php).
            'tax_setting' => ['required', 'string', Rule::in(app(TaxRegimeService::class)->settingValues())],
        ];
    }

    public function taxMode(): TaxMode
    {
        return $this->validated('tax_setting') === TaxMode::Disabled->value ? TaxMode::Disabled : TaxMode::Rate;
    }

    public function taxRatePct(): ?string
    {
        return $this->taxMode() === TaxMode::Rate ? substr((string) $this->validated('tax_setting'), strlen(TaxRegimeService::RATE_PREFIX)) : null;
    }
}
