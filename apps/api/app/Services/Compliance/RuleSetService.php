<?php

namespace App\Services\Compliance;

use App\Actions\Compliance\CopyRuleSetAction;
use App\Actions\Compliance\CreateRuleSetAction;
use App\Actions\Compliance\DeleteRuleSetAction;
use App\Actions\Compliance\PublishRuleSetAction;
use App\Actions\Compliance\UpdateRuleSetAction;
use App\Models\RuleSet;

/** The single write path of rule sets (the rules inside them go through RuleService). */
final class RuleSetService
{
    public function __construct(
        private CreateRuleSetAction $createRuleSet,
        private UpdateRuleSetAction $updateRuleSet,
        private DeleteRuleSetAction $deleteRuleSet,
        private PublishRuleSetAction $publishRuleSet,
        private CopyRuleSetAction $copyRuleSet,
    ) {}

    /** @param  array{code: string, version: string, name: string, scope_notes?: string|null, source_id?: string|null}  $data */
    public function create(array $data, ?int $userId = null): RuleSet
    {
        return ($this->createRuleSet)($data, $userId);
    }

    /** @param  array{code?: string, version?: string, name?: string, scope_notes?: string|null, source_id?: string|null}  $data */
    public function update(RuleSet $ruleSet, array $data): RuleSet
    {
        return ($this->updateRuleSet)($ruleSet, $data);
    }

    public function delete(RuleSet $ruleSet): void
    {
        ($this->deleteRuleSet)($ruleSet);
    }

    public function publish(RuleSet $ruleSet, ?string $expectedHash = null): RuleSet
    {
        return ($this->publishRuleSet)($ruleSet, $expectedHash);
    }

    public function newVersion(RuleSet $source, string $version, ?int $userId = null): RuleSet
    {
        return ($this->copyRuleSet)($source, $version, $userId);
    }
}
