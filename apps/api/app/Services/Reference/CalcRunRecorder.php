<?php

namespace App\Services\Reference;

use App\Models\CalcRun;

/** Records which engine version and dataset version produced a result (ADR 003 #2). */
class CalcRunRecorder
{
    public function record(
        string $inputHash,
        string $outputHash,
        string $engineVersion,
        string $datasetVersionId,
        ?string $tenantId = null,
    ): CalcRun {
        return CalcRun::create([
            'tenant_id' => $tenantId,
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'engine_version' => $engineVersion,
            'dataset_version' => $datasetVersionId,
        ]);
    }
}
