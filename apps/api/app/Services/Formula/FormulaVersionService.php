<?php

namespace App\Services\Formula;

use App\Actions\Formula\CreateFormulaVersionAction;
use App\Exceptions\Domain\InvalidFormulaVersionException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Every change to a formula is a new version row; existing versions are never touched. */
final class FormulaVersionService
{
    public function __construct(
        private CreateFormulaVersionAction $createFormulaVersion,
    ) {}

    /**
     * @param  list<array{trade_material_id: string, percent_ww?: string|int|null, is_qs?: bool, phase?: string|null}>  $lines
     *                                                                                                                          Order defines line_no (1-based); percent_ww is null only for a q.s. line.
     * @param  FormulaVersion|null  $basedOn  The version the new one was edited from (its parent); the latest version when omitted.
     *
     * @throws InvalidFormulaVersionException When there are no lines, or a version after the first has no reason.
     */
    public function createVersion(Formula $formula, array $lines, ?string $reason, ?User $user = null, ?FormulaVersion $basedOn = null): FormulaVersion
    {
        if ($lines === []) {
            throw InvalidFormulaVersionException::linesRequired();
        }

        return DB::transaction(function () use ($formula, $lines, $reason, $user, $basedOn): FormulaVersion {
            // Serialise concurrent saves of the same formula so version numbers do not collide.
            Formula::query()->whereKey($formula->getKey())->lockForUpdate()->firstOrFail();

            $latest = $formula->versions()->reorder()->orderByDesc('version')->first();
            $number = ($latest?->version ?? 0) + 1;

            if ($number > 1 && trim((string) $reason) === '') {
                throw InvalidFormulaVersionException::reasonRequired();
            }

            return ($this->createFormulaVersion)($formula, $lines, $number, $basedOn ?? $latest, $reason, $user);
        });
    }
}
