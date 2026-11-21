<?php

namespace App\Services\Lab;

use App\Enums\LabProperty;
use App\Enums\LabTrialStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use App\Support\DTOs\LabCoverage;

/**
 * What a formula version's lab results cover (the lab side of the approval rule AFV-06: no required measurement missing).
 * The required set comes from config/lab.php; a property counts as measured only when a completed trial holds a value for it.
 */
final class LabCoverageService
{
    /** @return list<string> The measurement keys the formula's product type needs before approval. */
    public function requiredFor(Formula $formula): array
    {
        $byType = (array) config('lab.required_measurements.by_product_type', []);

        return array_values((array) ($byType[$formula->product_type] ?? config('lab.required_measurements.default', [])));
    }

    /** The best completed trial (the one missing the fewest required measurements, the newest on a tie) decides. */
    public function forVersion(FormulaVersion $version): LabCoverage
    {
        $required = $this->requiredFor($version->formula);

        $trials = LabTrial::query()
            ->where('formula_version_id', $version->getKey())
            ->where('status', LabTrialStatus::Completed)
            ->with('measurements')
            ->orderByDesc('completed_at')
            ->get();

        $best = null;
        $bestMissing = null;

        foreach ($trials as $trial) {
            $held = $trial->measurements->map(fn ($measurement): string => $measurement->property->value)->unique()->all();
            $missing = array_values(array_diff($required, $held));

            if ($bestMissing === null || count($missing) < count($bestMissing)) {
                $best = $trial;
                $bestMissing = $missing;
            }
        }

        if ($best === null) {
            return new LabCoverage($required, [], $required, 0, null);
        }

        return new LabCoverage($required, array_values(array_diff($required, $bestMissing)), $bestMissing, $trials->count(), $best->getKey());
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> The translated names of measurement keys.
     */
    public function labels(array $keys): array
    {
        return array_map(fn (string $key): string => LabProperty::tryFrom($key)?->label() ?? $key, $keys);
    }
}
