<?php

namespace App\Actions\Exports;

use App\Models\ExportLog;
use App\Models\User;
use App\Support\Clock\Clock;
use App\Support\DTOs\PrintDocument;

/** Writes one export log row for a printed document: who, what, the hash on it, and when (the injected clock). */
final class RecordExportAction
{
    public function __construct(private readonly Clock $clock) {}

    public function __invoke(PrintDocument $document, string $tenantId, ?User $user, ?string $formulaId = null, ?string $formulaVersionId = null): ExportLog
    {
        return ExportLog::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->getKey(),
            'kind' => $document->kind,
            'subject_label' => $document->subjectLabel,
            'subject_id' => $document->subjectId,
            'formula_id' => $formulaId,
            'formula_version_id' => $formulaVersionId,
            'content_hash' => $document->hash,
            'watermark' => $document->watermark,
            'exported_at' => $this->clock->now(),
        ]);
    }
}
