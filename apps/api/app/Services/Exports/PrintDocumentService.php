<?php

namespace App\Services\Exports;

use App\Enums\ExportKind;
use App\Enums\PrintWatermark;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Support\CanonicalJson;
use App\Support\DTOs\PrintDocument;

/**
 * The one place that decides what is printed on a document's header and footer: its subject, its hash and its watermark (S-17).
 * Every printable page asks here, and the export log asks here again to record the same facts, so what was printed is what is logged.
 */
final class PrintDocumentService
{
    /** The label and the cost report of one formula version: stamped with the version hash. */
    public function forVersion(ExportKind $kind, Formula $formula, FormulaVersion $version): PrintDocument
    {
        return new PrintDocument(
            kind: $kind,
            subjectId: $version->getKey(),
            subjectLabel: "{$formula->code} v{$version->version}",
            hash: $version->hash,
            watermark: $version->isApproved() ? PrintWatermark::Approved : PrintWatermark::Unapproved,
            logUrl: route('exports.store', [], false),
        );
    }

    /** The weighing sheet of a production order: stamped with the hash of the approved version it came from. */
    public function forOrder(ProductionOrder $order): PrintDocument
    {
        $order->loadMissing('formulaVersion.formula');
        $version = $order->formulaVersion;

        return new PrintDocument(
            kind: ExportKind::WeighingSheet,
            subjectId: $order->getKey(),
            subjectLabel: "{$order->code} · {$version->formula->code} v{$version->version}",
            hash: $order->formula_hash,
            watermark: $version->isApproved() ? PrintWatermark::Approved : PrintWatermark::Unapproved,
            logUrl: route('exports.store', [], false),
        );
    }

    /**
     * The lot trace has no formula version; its hash covers what it shows (the lot, the material filter and each row's batch, order and quantity).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function forTrace(string $lot, ?string $materialId, array $rows): PrintDocument
    {
        return new PrintDocument(
            kind: ExportKind::LotTrace,
            subjectId: null,
            subjectLabel: $lot,
            hash: self::traceHash($lot, $materialId, $rows),
            watermark: PrintWatermark::Neutral,
            logUrl: route('exports.store', [], false),
            lot: $lot,
            materialId: $materialId,
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    public static function traceHash(string $lot, ?string $materialId, array $rows): string
    {
        return CanonicalJson::hash([
            'lot' => $lot,
            'material_id' => $materialId,
            'rows' => array_map(fn (array $row): array => [
                'id' => (string) $row['id'],
                'batch' => (string) $row['batch']['number'],
                'order' => (string) $row['order']['code'],
                'quantity' => (string) $row['quantity'],
                'uom' => (string) $row['uom'],
            ], $rows),
        ]);
    }
}
