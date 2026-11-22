<?php

namespace App\Services\Template;

use App\Enums\TemplateVerification;
use App\Http\Filters\ListQuery;
use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use Illuminate\Http\Request;

/** The template library list (K-09): search, family and verification status filters kept in the URL. */
final class TemplateListService
{
    /** @return array<string, mixed> */
    public function list(Request $request): array
    {
        return ListQuery::for(FormulaTemplate::query()->with('family'), $request)
            ->search(['code', 'name_ar', 'name_en'], 'templates.search_placeholder')
            ->select(
                'family',
                ProductFamily::query()->orderBy('position')->get()->map(fn (ProductFamily $family): array => ['value' => $family->getKey(), 'label' => $family->displayName()])->all(),
                column: 'product_family_id',
                labelKey: 'templates.filters.family',
            )
            ->select(
                'status',
                array_map(fn (TemplateVerification $case): array => ['value' => $case->value, 'label' => $case->label()], TemplateVerification::cases()),
                column: 'verification_status',
                labelKey: 'templates.filters.status',
            )
            ->sortable(['position', 'code', 'name' => 'name_ar', 'status' => 'verification_status'], default: 'position', labelPrefix: 'templates.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (FormulaTemplate $template): array => [
                'id' => $template->getKey(),
                'code' => $template->code,
                'name' => $template->displayName(),
                'family' => $template->family->displayName(),
                'status' => $template->verification_status->value,
                'lines' => count($template->lines),
                'missing_percents' => $template->missingPercentCount(),
                'href' => route('templates.show', $template, false),
            ]);
    }
}
