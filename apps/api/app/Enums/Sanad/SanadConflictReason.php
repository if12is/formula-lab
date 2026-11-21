<?php

namespace App\Enums\Sanad;

use App\Enums\Concerns\HasOptions;

/** Why a pushed item was not applied (contract section 2). */
enum SanadConflictReason: string
{
    use HasOptions;

    case FormulaLabNewer = 'formula_lab_newer';
    case InvalidReference = 'invalid_reference';
    case SchemaMismatch = 'schema_mismatch';
}
