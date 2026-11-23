<?php

namespace App\Http\Requests\Tiers;

use Illuminate\Support\Arr;

/** Saving the optimizer's answer as a draft version (J-09): only the inputs of the solve (product, tier, balancing material); the numbers are recomputed on the server. */
class SaveOptimizedVersionRequest extends SolveTierRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['formula_id', 'tier', 'qs_material_id']);
    }
}
