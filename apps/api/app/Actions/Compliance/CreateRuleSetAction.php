<?php

namespace App\Actions\Compliance;

use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\RuleSetException;
use App\Models\RuleSet;

/** Inserts an empty draft rule set. */
final class CreateRuleSetAction
{
    /**
     * @param  array{code: string, version: string, name: string, scope_notes?: string|null, source_id?: string|null}  $data
     *
     * @throws RuleSetException When the code already has that version.
     */
    public function __invoke(array $data, ?int $userId = null): RuleSet
    {
        if (RuleSet::query()->where('code', $data['code'])->where('version', $data['version'])->exists()) {
            throw RuleSetException::duplicateVersion($data['code'], $data['version']);
        }

        return RuleSet::query()->create([
            ...$data,
            'status' => RuleSetStatus::Draft,
            'created_by' => $userId,
        ]);
    }
}
