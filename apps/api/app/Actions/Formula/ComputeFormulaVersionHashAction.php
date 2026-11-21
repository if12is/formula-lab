<?php

namespace App\Actions\Formula;

use App\Models\FormulaLine;
use App\Models\FormulaVersion;

/** The content hash of a version: SHA-256 of the canonical JSON of its ordered lines. */
final class ComputeFormulaVersionHashAction
{
    /**
     * @param  list<FormulaLine>  $lines  In line_no order.
     *
     * @throws \InvalidArgumentException When a line holds a value the canonical encoder forbids.
     */
    public function __invoke(array $lines): string
    {
        return FormulaVersion::hashLines(array_map(fn (FormulaLine $line): array => $line->canonical(), $lines));
    }
}
