<?php

namespace App\Http\Requests\Sanad;

use App\Http\Middleware\AuthenticateSanadDevice;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\SanadDevice;
use Illuminate\Foundation\Http\FormRequest;

/** Base of the requests behind the `sanad.device` middleware: authorization is the device token itself. */
abstract class SanadApiRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return $this->attributes->get(AuthenticateSanadDevice::ATTRIBUTE) instanceof SanadDevice;
    }

    public function device(): SanadDevice
    {
        /** @var SanadDevice */
        return $this->attributes->get(AuthenticateSanadDevice::ATTRIBUTE);
    }
}
