<?php

namespace App\Actions\Formula;

use App\Models\Formula;

/** Sets the claims a formula's product makes (codes already validated against the disinfectant flag data), without repeats; none = null. */
final class UpdateFormulaClaimsAction
{
    /** @param  list<string>  $claims */
    public function __invoke(Formula $formula, array $claims): Formula
    {
        $claims = array_values(array_unique($claims));
        $formula->update(['claims' => $claims === [] ? null : $claims]);

        return $formula;
    }
}
