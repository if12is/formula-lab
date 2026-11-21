<?php

namespace App\Actions\Reference;

use App\Models\CalcRun;
use Illuminate\Database\QueryException;

/** Records which engine version and dataset version produced a result (ADR 003 #2). */
final class RecordCalcRunAction
{
    /**
     * @throws QueryException When the dataset version is unknown.
     */
    public function __invoke(
        string $inputHash,
        string $outputHash,
        string $engineVersion,
        string $datasetVersionId,
        ?string $tenantId = null,
    ): CalcRun {
        return CalcRun::query()->create([
            'tenant_id' => $tenantId,
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'engine_version' => $engineVersion,
            'dataset_version' => $datasetVersionId,
        ]);
    }
}
