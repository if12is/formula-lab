<?php

namespace App\Services\Family;

use App\Enums\DataVerification;
use App\Enums\Regulator;
use App\Http\Filters\ListQuery;
use App\Models\ProductFamily;
use App\Support\Families\FamilyData;
use Illuminate\Http\Request;

/** The product family list (K-01): search and filters kept in the URL, rows with their regulator, IFRA category and counts. */
final class FamilyListService
{
    public function __construct(private FamilyData $data) {}

    /** @return array<string, mixed> */
    public function list(Request $request): array
    {
        return ListQuery::for(ProductFamily::query()->withCount('templates'), $request)
            ->search(['code', 'name_ar', 'name_en'], 'families.search_placeholder')
            ->select('group', $this->groupOptions(), labelKey: 'families.filters.group')
            ->select('regulator', $this->options(Regulator::cases()), labelKey: 'families.filters.regulator')
            ->select('ifra_category', $this->ifraOptions(), labelKey: 'families.filters.ifra_category')
            ->select('verification', $this->options(DataVerification::cases()), labelKey: 'families.filters.verification')
            ->sortable(['position', 'code', 'name' => 'name_ar', 'group', 'regulator'], default: 'position', labelPrefix: 'families.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (ProductFamily $family): array => [
                'id' => $family->getKey(),
                'code' => $family->code,
                'name' => $family->displayName(),
                'group' => $family->group,
                'regulator' => $family->regulator?->value,
                'ifra_category' => $family->ifra_category,
                'calculations' => count($family->required_calculations),
                'properties' => count($family->measured_properties),
                'templates' => (int) $family->getAttribute('templates_count'),
                'verification' => $family->verification->value,
                'href' => route('families.show', $family, false),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function groupOptions(): array
    {
        return array_map(
            fn (string $group): array => ['value' => $group, 'label' => __("families.groups.{$group}")],
            $this->data->registry()['vocabulary']['groups'],
        );
    }

    /**
     * @param  list<Regulator|DataVerification>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (Regulator|DataVerification $case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /** @return list<array{value: string, label: string}> The IFRA categories the registry actually uses. */
    private function ifraOptions(): array
    {
        return ProductFamily::query()->whereNotNull('ifra_category')->distinct()->orderBy('ifra_category')->pluck('ifra_category')
            ->map(fn (string $category): array => ['value' => $category, 'label' => $category])->all();
    }
}
