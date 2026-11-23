<?php

namespace App\Services\Coa;

use App\Enums\Coa\CoaExtractionStatus;
use App\Http\Filters\ListQuery;
use App\Models\CoaExtraction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the COA list: every extraction with its material, lot, status (the latest event) and how many fields it holds; filtered, sorted and paginated. */
final class CoaExtractionListService
{
    /**
     * The whole `list` prop for /coa.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $materialOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['file_name', 'lot_no', 'supplier', 'material_name', 'file_sha256'], 'coa.search_placeholder')
            ->select('status', array_map(fn (CoaExtractionStatus $status): array => ['value' => $status->value, 'labelKey' => "coa.enums.status.{$status->value}"], CoaExtractionStatus::cases()), labelKey: 'coa.filters.status')
            ->select('trade_material_id', optionsUrl: $materialOptionsUrl, labelKey: 'coa.filters.material')
            ->dateRange('created_at', labelKey: 'coa.filters.created_at')
            ->sortable(['when' => 'created_at', 'material' => 'material_name', 'lot' => 'lot_no', 'status', 'fields' => 'field_count'], default: 'when', direction: 'desc', labelPrefix: 'coa.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (CoaExtraction $extraction): array => [
                'id' => $extraction->getKey(),
                'material_name' => $extraction->getAttribute('material_name'),
                'file_name' => $extraction->file_name,
                'supplier' => $extraction->supplier,
                'lot_no' => $extraction->lot_no,
                'status' => $extraction->getAttribute('status'),
                'field_count' => (int) $extraction->getAttribute('field_count'),
                'text_check' => $extraction->text_check,
                'created_at' => $extraction->created_at->toIso8601String(),
                'href' => route('coa.show', $extraction, false),
            ]);
    }

    /** @return Builder<CoaExtraction> */
    private function listing(): Builder
    {
        $derived = CoaExtraction::query()
            ->select('coa_extractions.*')
            ->selectRaw('trade_materials.trade_name as material_name')
            ->selectRaw('(select e.status from coa_extraction_events e where e.coa_extraction_id = coa_extractions.id order by e.id desc limit 1) as status')
            ->selectRaw('(select count(*) from coa_extraction_fields f where f.coa_extraction_id = coa_extractions.id) as field_count')
            ->join('trade_materials', 'trade_materials.id', '=', 'coa_extractions.trade_material_id');

        return CoaExtraction::query()->fromSub($derived, 'coa_extractions');
    }
}
