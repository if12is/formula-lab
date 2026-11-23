<?php

namespace App\Services\Eda;

use App\Exceptions\Domain\EdaChecklistException;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Support\Engine\EngineRunner;
use Illuminate\Database\Eloquent\Collection;

/**
 * Asks the engine (scripts/eda-checklist.ts) what each product's EDA notification file needs and how complete it is. PHP gathers the group of
 * the product's family and what was recorded per item; it decides nothing about what a file contains. Many products go in one engine run.
 */
final class EdaReadinessService
{
    public function __construct(private readonly EngineRunner $engine) {}

    /** @return array<string, mixed> The readiness of one product, plus `orphans`: recorded items the checklist no longer has. */
    public function forFormula(Formula $formula): array
    {
        return $this->forFormulas(new Collection([$formula]))[$formula->getKey()];
    }

    /**
     * @param  Collection<int, Formula>  $formulas
     * @return array<string, array<string, mixed>> By formula id: `{ state, checklistId, regulator, reference, items, counts, warnings, orphans }`.
     *
     * @throws EdaChecklistException When the engine refuses the checklist data itself.
     */
    public function forFormulas(Collection $formulas): array
    {
        $formulas->loadMissing(['productFamily', 'edaChecklistEntries']);
        $requests = $formulas->map(fn (Formula $formula): array => [
            'familyGroup' => $formula->productFamily?->group,
            'statuses' => $this->statuses($formula),
        ])->values()->all();

        $answers = $this->run($requests);
        $out = [];
        foreach ($formulas->values() as $index => $formula) {
            $answer = $answers[$index];
            $orphans = [];
            if (! $answer['ok']) {
                // An entry whose item is no longer in the checklist (or has a status the engine does not know) is set aside and listed, never dropped silently.
                $orphans = array_values(array_unique(array_map(fn (array $gap): string => substr((string) $gap['property'], strlen('entries:')), array_filter($answer['missing'], fn (array $gap): bool => str_starts_with((string) $gap['property'], 'entries:')))));
                $kept = array_diff_key($requests[$index]['statuses'], array_flip($orphans));
                $answer = $orphans === [] ? $answer : $this->run([['familyGroup' => $requests[$index]['familyGroup'], 'statuses' => $kept]])[0];
            }
            if (! $answer['ok']) {
                throw EdaChecklistException::dataInvalid();
            }
            $out[$formula->getKey()] = [...$answer['value'], 'warnings' => $answer['warnings'], 'orphans' => $orphans];
        }

        return $out;
    }

    /** @return array<string, string> Recorded status by item code. */
    private function statuses(Formula $formula): array
    {
        return $formula->edaChecklistEntries->mapWithKeys(fn (EdaChecklistEntry $entry): array => [$entry->item_code => $entry->status->value])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>
     */
    private function run(array $requests): array
    {
        $input = ['requests' => array_map(fn (array $request): array => [...$request, 'statuses' => (object) $request['statuses']], $requests)];
        $path = config('eda.definitions_path');
        if (is_string($path) && $path !== '') {
            $input['data'] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->engine->run('eda-checklist', $input)['answers'];
    }
}
