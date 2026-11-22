<?php

namespace App\Services\Family;

use App\Models\ProductFamily;

/** The families as select options (the formula form's "product family" field): [{ value: code, label, description }]. */
final class FamilyOptionService
{
    /** @return list<array{value: string, label: string}> */
    public function options(): array
    {
        return ProductFamily::query()->orderBy('position')->get()
            ->map(fn (ProductFamily $family): array => ['value' => $family->code, 'label' => $family->displayName()])
            ->all();
    }
}
