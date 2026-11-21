<?php

namespace App\Http\Requests\Dev;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use Illuminate\Foundation\Http\FormRequest;

/** Base of the mock Sanad write requests: the mock token middleware already did the access check. */
abstract class MockSanadRequest extends FormRequest
{
    use TranslatesValidationMessages;

    protected const DECIMAL = '/^\d+(\.\d+)?$/';

    public function authorize(): bool
    {
        return true;
    }
}
