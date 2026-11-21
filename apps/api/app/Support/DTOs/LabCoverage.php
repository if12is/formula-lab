<?php

namespace App\Support\DTOs;

/**
 * How far a version's lab results go towards what its approval needs: the measurements required, the ones the best
 * completed trial holds, and the ones still missing. A version is covered only when a completed trial holds them all.
 */
final readonly class LabCoverage
{
    /**
     * @param  list<string>  $required
     * @param  list<string>  $measured  Required properties the reference trial holds.
     * @param  list<string>  $missing  Required properties the reference trial lacks.
     */
    public function __construct(
        public array $required,
        public array $measured,
        public array $missing,
        public int $completedTrials,
        public ?string $referenceTrialId,
    ) {}

    /** True when at least one completed trial holds every required measurement. */
    public function isCovered(): bool
    {
        return $this->completedTrials > 0 && $this->missing === [];
    }

    /** @return array{required: list<string>, measured: list<string>, missing: list<string>, completed_trials: int, reference_trial_id: string|null, covered: bool} */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'measured' => $this->measured,
            'missing' => $this->missing,
            'completed_trials' => $this->completedTrials,
            'reference_trial_id' => $this->referenceTrialId,
            'covered' => $this->isCovered(),
        ];
    }
}
