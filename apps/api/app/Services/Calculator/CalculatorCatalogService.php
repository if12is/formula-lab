<?php

namespace App\Services\Calculator;

/** The calculators of config/calculators.php, shaped for the index cards and for each calculator page's lookups. */
final class CalculatorCatalogService
{
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('calculators.items'));
    }

    public function component(string $key): string
    {
        return config('calculators.items')[$key]['component'];
    }

    /**
     * Cards grouped for the index page, in the order of the config.
     *
     * @return list<array{group: string, items: list<array{key: string, href: string}>}>
     */
    public function groups(): array
    {
        $items = collect(config('calculators.items'));

        return collect(config('calculators.groups'))
            ->map(fn (string $group): array => [
                'group' => $group,
                'items' => $items
                    ->filter(fn (array $item): bool => $item['group'] === $group)
                    ->map(fn (array $item, string $key): array => ['key' => $key, 'href' => route('calculators.show', $key, false)])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /** @return array<string, string> */
    public function lookupUrls(): array
    {
        return [
            'materials' => route('calculators.materials.search', [], false),
            'sources' => route('sources.search', [], false),
            'sourceStore' => route('sources.store', [], false),
        ];
    }
}
