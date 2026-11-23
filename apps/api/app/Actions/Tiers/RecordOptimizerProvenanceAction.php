<?php

namespace App\Actions\Tiers;

use App\Enums\FormulaVersionOrigin;
use App\Enums\ProductTier;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionProvenance;
use App\Models\TierProfile;

/** Writes the provenance row of a version made from an optimizer answer: the inputs it read, the profile record, the solver and the hashes. */
final class RecordOptimizerProvenanceAction
{
    /**
     * @param  array<string, mixed>  $request  What was sent to the optimizer.
     * @param  array<string, mixed>  $answer  What it answered (status optimal).
     */
    public function __invoke(FormulaVersion $version, ProductTier $tier, ?TierProfile $profile, array $request, array $answer): FormulaVersionProvenance
    {
        return FormulaVersionProvenance::query()->create([
            'formula_version_id' => $version->getKey(),
            'origin' => FormulaVersionOrigin::Optimizer,
            'tier_profile_id' => $profile?->getKey(),
            'tier' => $tier->value,
            'optimizer_inputs' => $request,
            'optimizer_version' => $answer['optimizerVersion'],
            'solver' => $answer['solver']['name'].' '.$answer['solver']['packageVersion'],
            'input_hash' => $answer['inputHash'],
            'output_hash' => $answer['outputHash'],
            'cost_per_kg' => $answer['costPerKg'],
        ]);
    }
}
