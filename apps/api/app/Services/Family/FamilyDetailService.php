<?php

namespace App\Services\Family;

use App\Models\FormulaTemplate;
use App\Models\ProductFamily;
use App\Support\Families\FamilyData;

/**
 * One family as the page shows it: what it needs calculated and measured (with what the platform can already do about each),
 * what the registry does not state (shown as missing, never as a value) and the templates that belong to it.
 */
final class FamilyDetailService
{
    public function __construct(private FamilyData $data) {}

    /** @return array<string, mixed> */
    public function forFamily(ProductFamily $family): array
    {
        $family->load('templates');

        return [
            'family' => [
                'id' => $family->getKey(),
                'code' => $family->code,
                'name' => $family->displayName(),
                'group' => $family->group,
                'regulator' => $family->regulator?->value,
                'ifra_category' => $family->ifra_category,
                'tax_regime' => $family->tax_regime,
                'note' => $family->notes_key,
                'confidence' => $family->confidence->value,
                'verification' => $family->verification->value,
                'calculations' => array_map(fn (string $id): array => $this->calculation($id), $family->required_calculations),
                'properties' => array_map(fn (string $id): array => $this->property($id), $family->measured_properties),
                'templates' => $family->templates->map(fn (FormulaTemplate $template): array => [
                    'id' => $template->getKey(),
                    'code' => $template->code,
                    'name' => $template->displayName(),
                    'verification_status' => $template->verification_status->value,
                    'href' => route('templates.show', $template, false),
                ])->all(),
            ],
            'urls' => ['index' => route('families.index', [], false)],
        ];
    }

    /** @return array{id: string, status: string, href: string|null} */
    private function calculation(string $id): array
    {
        $entry = $this->data->calculation($id);

        return [
            'id' => $id,
            'status' => $entry['status'] ?? 'planned',
            'href' => isset($entry['calculatorKey']) ? route('calculators.show', $entry['calculatorKey'], false) : null,
        ];
    }

    /** @return array{id: string, lab_property: string|null} */
    private function property(string $id): array
    {
        return ['id' => $id, 'lab_property' => $this->data->labPropertyOf($id)];
    }
}
