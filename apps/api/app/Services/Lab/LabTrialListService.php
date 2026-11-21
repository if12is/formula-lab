<?php

namespace App\Services\Lab;

use App\Enums\FormulaStatus;
use App\Enums\LabTrialStatus;
use App\Http\Filters\ListQuery;
use App\Models\Formula;
use App\Models\LabTrial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the trials list: filtered, sorted, paginated, each trial with its formula and version. */
final class LabTrialListService
{
    /**
     * The whole `list` prop for /lab/trials.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $formulaOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['code', 'operator', 'formula_code', 'formula_name'], 'lab.trials.search_placeholder')
            ->select('formula_id', optionsUrl: $formulaOptionsUrl, labelKey: 'lab.trials.filters.formula')
            ->select('status', LabTrialStatus::class, labelKey: 'lab.trials.filters.status')
            ->select('version_status', FormulaStatus::class, labelKey: 'lab.trials.filters.version_status')
            ->dateRange('trial_date', labelKey: 'lab.trials.filters.trial_date')
            ->sortable(['code', 'formula' => 'formula_code', 'date' => 'trial_date', 'status'], default: 'date', direction: 'desc', labelPrefix: 'lab.trials.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (LabTrial $trial): array => [
                'id' => $trial->getKey(),
                'code' => $trial->code,
                'formula_code' => $trial->getAttribute('formula_code'),
                'formula_name' => $trial->getAttribute('formula_name'),
                'version' => $trial->getAttribute('version_number'),
                'version_status' => $trial->getAttribute('version_status'),
                'status' => $trial->status->value,
                'trial_date' => $trial->trial_date?->toDateString(),
                'batch_size' => $trial->batch_size,
                'batch_unit' => $trial->batch_unit,
                'operator' => $trial->operator,
                'measurements' => (int) $trial->getAttribute('measured_properties'),
                'href' => route('lab.trials.show', $trial, false),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    public function formulaOptions(): array
    {
        return Formula::query()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Formula $formula): array => ['value' => $formula->getKey(), 'label' => "{$formula->code} — {$formula->name}"])
            ->all();
    }

    /**
     * Trials with their formula, version and the number of distinct properties measured, so the list can filter and sort by them.
     *
     * @return Builder<LabTrial>
     */
    private function listing(): Builder
    {
        $derived = LabTrial::query()
            ->select('lab_trials.*')
            ->selectRaw('formulas.id as formula_id, formulas.code as formula_code, formulas.name as formula_name')
            ->selectRaw('formula_versions.version as version_number, formula_versions.status as version_status')
            ->selectRaw('(select count(distinct property) from lab_measurements where lab_measurements.lab_trial_id = lab_trials.id) as measured_properties')
            ->join('formula_versions', 'formula_versions.id', '=', 'lab_trials.formula_version_id')
            ->join('formulas', 'formulas.id', '=', 'formula_versions.formula_id');

        return LabTrial::query()->fromSub($derived, 'lab_trials');
    }
}
