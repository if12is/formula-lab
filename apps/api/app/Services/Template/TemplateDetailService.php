<?php

namespace App\Services\Template;

use App\Enums\ProductTier;
use App\Models\FormulaTemplate;

/** One template as the page shows it: its lines by role (a share is shown only where a source gave one) and the use form's lookups. */
final class TemplateDetailService
{
    /** @return array<string, mixed> */
    public function forTemplate(FormulaTemplate $template): array
    {
        $template->loadMissing('family');

        return [
            'template' => [
                'id' => $template->getKey(),
                'code' => $template->code,
                'name' => $template->displayName(),
                'status' => $template->verification_status->value,
                'approved_by' => $template->approved_by,
                'approved_on' => $template->approved_on?->toDateString(),
                'confidence' => $template->confidence->value,
                'family' => [
                    'name' => $template->family->displayName(),
                    'regulator' => $template->family->regulator?->value,
                    'href' => route('families.show', $template->family, false),
                ],
                'lines' => array_map(fn (array $line): array => [
                    'key' => $line['key'],
                    'role' => $line['role'],
                    'percent' => $line['percentWW'],
                    'qs' => $line['qs'],
                    'optional' => $line['optional'],
                ], $template->lines),
                'missing_percents' => $template->missingPercentCount(),
            ],
            'tierOptions' => ProductTier::options(),
            'urls' => [
                'index' => route('templates.index', [], false),
                'use' => route('templates.use', $template, false),
                'materials' => route('materials.search', [], false),
            ],
        ];
    }
}
