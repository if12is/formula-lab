<?php

namespace App\Services\Eda;

use App\Enums\Regulator;
use App\Http\Filters\ListQuery;
use App\Models\Formula;
use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** The products whose family EDA regulates, each with how ready its notification file is (the engine's answer), filtered, sorted and paginated. */
final class EdaFileListService
{
    public function __construct(private readonly EdaReadinessService $readiness) {}

    /**
     * The whole `list` prop for /eda.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request): array
    {
        $list = ListQuery::for($this->listing(), $request)
            ->search(['code', 'name'], 'eda.search_placeholder')
            ->select('family', $this->familyOptions(), labelKey: 'eda.filters.family', using: fn (Builder $query, string $code) => $query->whereHas('productFamily', fn (Builder $family) => $family->where('code', $code)))
            ->select('group', $this->groupOptions(), labelKey: 'eda.filters.group', using: fn (Builder $query, string $group) => $query->whereHas('productFamily', fn (Builder $family) => $family->where('group', $group)))
            ->sortable(['code', 'name'], default: 'code', labelPrefix: 'eda.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (Formula $formula): array => ['id' => $formula->getKey(), 'code' => $formula->code, 'name' => $formula->name, 'family' => $formula->productFamily?->displayName()]);

        $answers = $this->readiness->forFormulas(Formula::query()->whereKey(array_column($list['rows'], 'id'))->get());

        $list['rows'] = array_map(fn (array $row): array => [
            ...$row,
            'state' => $answers[$row['id']]['state'],
            'counts' => $answers[$row['id']]['counts'],
            'href' => route('eda.show', $row['id'], false),
        ], $list['rows']);

        return $list;
    }

    /**
     * How many EDA products are in each readiness state, across all of them (not only this page).
     *
     * @return array{total: int, states: array<string, int>}
     */
    public function summary(): array
    {
        $formulas = $this->listing()->get();
        $states = [];
        foreach ($this->readiness->forFormulas($formulas) as $answer) {
            $states[$answer['state']] = ($states[$answer['state']] ?? 0) + 1;
        }

        return ['total' => $formulas->count(), 'states' => $states];
    }

    /** @return Builder<Formula> */
    private function listing(): Builder
    {
        return Formula::query()->with('productFamily')->whereHas('productFamily', fn (Builder $family) => $family->where('regulator', Regulator::Eda));
    }

    /** @return list<array{value: string, label: string}> */
    private function familyOptions(): array
    {
        return ProductFamily::query()->where('regulator', Regulator::Eda)->orderBy('position')->get()
            ->map(fn (ProductFamily $family): array => ['value' => $family->code, 'label' => $family->displayName()])->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function groupOptions(): array
    {
        return ProductFamily::query()->where('regulator', Regulator::Eda)->distinct()->orderBy('group')->pluck('group')
            ->map(fn (string $group): array => ['value' => $group, 'label' => __("families.groups.{$group}")])->all();
    }
}
