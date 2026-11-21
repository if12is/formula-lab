<?php

namespace App\Actions\Formula;

use App\Models\Formula;

/** Sets the IFRA category of a formula's product (free text as the certificates name it), or clears it. */
final class UpdateFormulaIfraCategoryAction
{
    public function __invoke(Formula $formula, ?string $category): Formula
    {
        $category = $category === null ? null : trim($category);
        $formula->update(['ifra_category' => $category === '' ? null : $category]);

        return $formula;
    }
}
