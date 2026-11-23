<?php

namespace App\Services\Exports;

use App\Actions\Exports\RecordExportAction;
use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\ExportKind;
use App\Models\ExportLog;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\Production\ProductionTraceService;

/**
 * The single write path of the export log (U-03). The browser only says WHICH document was printed; the hash, the subject and the watermark
 * are worked out again here from the stored record, so the log can never carry a hash the browser made up.
 */
final class ExportLogService
{
    public function __construct(
        private readonly PrintDocumentService $documents,
        private readonly RecordExportAction $recordExport,
        private readonly ProductionTraceService $traceService,
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
    ) {}

    /** @param  array{kind: ExportKind, subject_id?: string|null, lot?: string|null, material_id?: string|null}  $data */
    public function record(array $data, ?User $user): ExportLog
    {
        return match ($data['kind']) {
            ExportKind::FormulaLabel, ExportKind::CostReport => $this->recordVersion($data['kind'], (string) $data['subject_id'], $user),
            ExportKind::WeighingSheet => $this->recordOrder((string) $data['subject_id'], $user),
            ExportKind::LotTrace => $this->recordTrace((string) $data['lot'], $data['material_id'] ?? null, $user),
        };
    }

    private function recordVersion(ExportKind $kind, string $versionId, ?User $user): ExportLog
    {
        $version = FormulaVersion::query()->with('formula')->findOrFail($versionId);

        return ($this->recordExport)(
            $this->documents->forVersion($kind, $version->formula, $version),
            $version->formula->tenant_id,
            $user,
            $version->formula_id,
            $version->getKey(),
        );
    }

    private function recordOrder(string $orderId, ?User $user): ExportLog
    {
        $order = ProductionOrder::query()->with('formulaVersion.formula')->findOrFail($orderId);

        return ($this->recordExport)(
            $this->documents->forOrder($order),
            $order->tenant_id,
            $user,
            $order->formulaVersion->formula_id,
            $order->formula_version_id,
        );
    }

    private function recordTrace(string $lot, ?string $materialId, ?User $user): ExportLog
    {
        $document = $this->documents->forTrace($lot, $materialId, $this->traceService->byLot($lot, $materialId));

        return ($this->recordExport)($document, ($this->ensureDefaultTenant)()->getKey(), $user);
    }
}
