<?php

namespace App\Http\Requests\Compliance;

use App\Http\Requests\Compliance\Concerns\ValidatesRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRuleRequest extends FormRequest
{
    use ValidatesRule;

    public function authorize(): bool
    {
        return true;
    }
}
