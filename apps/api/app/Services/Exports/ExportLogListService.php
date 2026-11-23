<?php

namespace App\Services\Exports;

use App\Enums\ExportKind;
use App\Enums\PrintWatermark;
use App\Http\Filters\ListQuery;
use App\Models\ExportLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the export log screen (U-03): filtered, sorted, paginated; each row with who, what, when, the hash and a link back to the document. */
final class ExportLogListService
{
    /**
     * The whole `list` prop for /exports.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $formulaOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['subject_label', 'content_hash', 'user_name', 'formula_code'], 'exports.search_placeholder')
            ->select('kind', ExportKind::class, labelKey: 'exports.filters.kind')
            ->select('formula_id', optionsUrl: $formulaOptionsUrl, labelKey: 'exports.filters.formula')
            ->select('watermark', PrintWatermark::class, labelKey: 'exports.filters.watermark')
            ->dateRange('exported_at', labelKey: 'exports.filters.exported_at')
            ->sortable(['when' => 'exported_at', 'kind', 'subject' => 'subject_label', 'user' => 'user_name'], default: 'when', direction: 'desc', labelPrefix: 'exports.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (ExportLog $log): array => [
                'id' => $log->getKey(),
                'kind' => $log->kind->value,
                'subject_label' => $log->subject_label,
                'content_hash' => $log->content_hash,
                'watermark' => $log->watermark->value,
                'user_name' => $log->getAttribute('user_name'),
                'exported_at' => $log->exported_at->toIso8601String(),
                'href' => $this->href($log),
            ]);
    }

    /** @return Builder<ExportLog> */
    private function listing(): Builder
    {
        $derived = ExportLog::query()
            ->select('export_logs.*')
            ->selectRaw('users.name as user_name, formulas.code as formula_code, formula_versions.version as version_number')
            ->leftJoin('users', 'users.id', '=', 'export_logs.user_id')
            ->leftJoin('formulas', 'formulas.id', '=', 'export_logs.formula_id')
            ->leftJoin('formula_versions', 'formula_versions.id', '=', 'export_logs.formula_version_id');

        return ExportLog::query()->fromSub($derived, 'export_logs');
    }

    /** The page the document was printed from, so a row opens it again. */
    private function href(ExportLog $log): ?string
    {
        $number = $log->getAttribute('version_number');

        return match ($log->kind) {
            ExportKind::FormulaLabel => $log->formula_id && $number ? route('formulas.versions.label', [$log->formula_id, $number], false) : null,
            ExportKind::CostReport => $log->formula_id && $number ? route('formulas.cost-report', ['formula' => $log->formula_id, 'version' => $number], false) : null,
            ExportKind::WeighingSheet => $log->subject_id ? route('production.orders.weighing', $log->subject_id, false) : null,
            ExportKind::LotTrace => route('production.trace', ['lot' => $log->subject_label], false),
        };
    }
}
