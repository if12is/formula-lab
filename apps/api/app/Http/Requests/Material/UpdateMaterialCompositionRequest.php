<?php

namespace App\Http\Requests\Material;

use App\Http\Requests\Material\Concerns\ValidatesMaterialComposition;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialCompositionRequest extends FormRequest
{
    use ValidatesMaterialComposition;

    public function authorize(): bool
    {
        return true;
    }
}
