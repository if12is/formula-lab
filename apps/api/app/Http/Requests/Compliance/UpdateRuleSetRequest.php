<?php

namespace App\Http\Requests\Compliance;

use App\Http\Requests\Compliance\Concerns\ValidatesRuleSet;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRuleSetRequest extends FormRequest
{
    use ValidatesRuleSet;

    public function authorize(): bool
    {
        return true;
    }
}
