<?php

namespace App\Services\Formula;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Every change to a formula is a new version row; existing versions are never touched. */
class FormulaVersionService
{
    /**
     * @param  list<array{trade_material_id: string, percent_ww?: string|int|null, is_qs?: bool, phase?: string|null}>  $lines
     *                                                                                                                          Order defines line_no (1-based); percent_ww is null only for a q.s. line.
     */
    public function createVersion(Formula $formula, array $lines, ?string $reason, ?User $user = null): FormulaVersion
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A formula version needs at least one line.');
        }

        return DB::transaction(function () use ($formula, $lines, $reason, $user): FormulaVersion {
            // Serialise concurrent saves of the same formula so version numbers do not collide.
            Formula::query()->whereKey($formula->getKey())->lockForUpdate()->firstOrFail();

            $latest = $formula->versions()->reorder()->orderByDesc('version')->first();
            $number = ($latest?->version ?? 0) + 1;

            if ($number > 1 && trim((string) $reason) === '') {
                throw new InvalidArgumentException('A reason is required for every version after the first.');
            }

            $lineModels = [];
            foreach (array_values($lines) as $index => $line) {
                $lineModels[] = new FormulaLine([
                    'line_no' => $index + 1,
                    'trade_material_id' => $line['trade_material_id'],
                    'percent_ww' => $line['percent_ww'] ?? null,
                    'is_qs' => $line['is_qs'] ?? false,
                    'phase' => $line['phase'] ?? null,
                ]);
            }

            $version = $formula->versions()->create([
                'version' => $number,
                'hash' => FormulaVersion::hashLines(array_map(fn (FormulaLine $l) => $l->canonical(), $lineModels)),
                'status' => FormulaStatus::Draft,
                'reason' => $number > 1 ? $reason : null,
                'parent_version_id' => $latest?->getKey(),
                'created_by' => $user?->getKey(),
            ]);

            $version->lines()->saveMany($lineModels);

            return $version->load('lines');
        });
    }
}
