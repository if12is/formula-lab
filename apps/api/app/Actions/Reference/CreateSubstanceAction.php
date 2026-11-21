<?php

namespace App\Actions\Reference;

use App\Models\Substance;
use Illuminate\Database\QueryException;

/** Inserts one substance (an identity: INCI, CAS, names, molar mass). */
final class CreateSubstanceAction
{
    /**
     * @param  array{cas?: string|null, inci?: string|null, name_en?: string|null, name_ar?: string|null, mw?: string|null, formula?: string|null}  $data
     *
     * @throws QueryException When the CAS number already belongs to another substance.
     */
    public function __invoke(array $data): Substance
    {
        return Substance::query()->create($data);
    }
}
