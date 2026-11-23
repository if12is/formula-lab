<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** The documents that can be printed (or saved as PDF) from a print preview, and so appear in the export log (U-03). */
enum ExportKind: string
{
    use HasOptions;

    case FormulaLabel = 'formula_label';
    case CostReport = 'cost_report';
    case WeighingSheet = 'weighing_sheet';
    case LotTrace = 'lot_trace';
}
