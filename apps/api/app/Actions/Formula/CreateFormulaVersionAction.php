<?php

namespace App\Actions\Formula;

use App\Enums\FormulaStatus;
use App\Exceptions\Domain\InvalidFormulaVersionException;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Inserts one new draft version row with its lines; existing versions are never touched. */
final class CreateFormulaVersionAction
{
    public function __construct(
        private ComputeFormulaVersionHashAction $computeHash,
    ) {}

    /**
     * @param  list<array{trade_material_id: string, percent_ww?: string|int|null, is_qs?: bool, phase?: string|null, addition_temp_c?: string|null}>  $lines
     *                                                                                                                                                         Order defines line_no (1-based); percent_ww is null only for a q.s. line.
     *
     * @throws \InvalidArgumentException When a percentage is not a plain decimal string (floats are rejected).
     * @throws InvalidFormulaVersionException When a line names a material that does not exist (the encrypted column has no foreign key).
     * @throws QueryException When the database refuses a row (missing percentage, duplicate number).
     */
    public function __invoke(Formula $formula, array $lines, int $number, ?FormulaVersion $parent, ?string $reason, ?User $user = null): FormulaVersion
    {
        return DB::transaction(function () use ($formula, $lines, $number, $parent, $reason, $user): FormulaVersion {
            // trade_material_id is encrypted at rest (U-02), so the database cannot hold a foreign key on it: the check lives here.
            $materialIds = array_values(array_unique(array_column($lines, 'trade_material_id')));
            $known = TradeMaterial::query()->whereKey($materialIds)->pluck('id')->all();
            foreach ($materialIds as $materialId) {
                if (! in_array($materialId, $known, true)) {
                    throw InvalidFormulaVersionException::unknownMaterial((string) $materialId);
                }
            }

            $lineModels = [];
            foreach (array_values($lines) as $index => $line) {
                $lineModels[] = new FormulaLine([
                    'line_no' => $index + 1,
                    'trade_material_id' => $line['trade_material_id'],
                    'percent_ww' => $line['percent_ww'] ?? null,
                    'is_qs' => $line['is_qs'] ?? false,
                    'phase' => $line['phase'] ?? null,
                    'addition_temp_c' => $line['addition_temp_c'] ?? null,
                ]);
            }

            $version = $formula->versions()->create([
                'version' => $number,
                'hash' => ($this->computeHash)($lineModels),
                'status' => FormulaStatus::Draft,
                'reason' => $number > 1 ? $reason : null,
                'parent_version_id' => $parent?->getKey(),
                'created_by' => $user?->getKey(),
            ]);

            $version->lines()->saveMany($lineModels);

            return $version->load('lines');
        });
    }
}
