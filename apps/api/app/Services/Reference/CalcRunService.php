<?php

namespace App\Services\Reference;

use App\Actions\Reference\RecordCalcRunAction;
use App\Models\CalcRun;

/** The single write path of calc_runs. */
final class CalcRunService
{
    public function __construct(
        private RecordCalcRunAction $recordCalcRun,
    ) {}

    public function record(
        string $inputHash,
        string $outputHash,
        string $engineVersion,
        string $datasetVersionId,
        ?string $tenantId = null,
    ): CalcRun {
        return ($this->recordCalcRun)($inputHash, $outputHash, $engineVersion, $datasetVersionId, $tenantId);
    }
}
