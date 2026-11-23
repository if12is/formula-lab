<?php

namespace App\Enums\Assistant;

use App\Enums\Concerns\HasOptions;

/** A kind of the owner's data that can reach the model provider, and so needs the user's consent for the session (P-10). */
enum AssistantDataCategory: string
{
    use HasOptions;

    /** Which materials a formula holds and in what percentages. */
    case FormulaComposition = 'formula_composition';

    /** What a formula or a material in it costs. */
    case FormulaCost = 'formula_cost';

    /** A supplier's certificate of analysis (the file itself or the values read from it). */
    case SupplierCertificate = 'supplier_certificate';
}
