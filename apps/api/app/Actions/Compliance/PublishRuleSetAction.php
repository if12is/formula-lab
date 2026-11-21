<?php

namespace App\Actions\Compliance;

use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\EngineException;
use App\Exceptions\Domain\RuleSetException;
use App\Models\RuleSet;
use App\Support\Compliance\RuleSetAssembler;
use App\Support\Engine\EngineRunner;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a draft rule set: the engine's validator (run in Node, the same code the browser ran) must accept the rule
 * set as `published`, which also rejects a rule that still waits for its source. The hash it returns is stored and the
 * rule set becomes immutable. `$expectedHash` is the hash the person saw in the confirmation: if the rules changed since,
 * nothing is published.
 */
final class PublishRuleSetAction
{
    public function __construct(
        private EngineRunner $engine,
        private RuleSetAssembler $assembler,
    ) {}

    /**
     * @throws RuleSetException When the rule set is not a draft, has no rules, fails validation or changed since review.
     * @throws EngineException When the engine cannot be reached.
     */
    public function __invoke(RuleSet $ruleSet, ?string $expectedHash = null): RuleSet
    {
        return DB::transaction(function () use ($ruleSet, $expectedHash): RuleSet {
            $locked = RuleSet::query()->lockForUpdate()->findOrFail($ruleSet->getKey());

            if ($locked->isPublished()) {
                throw RuleSetException::notDraft($locked->code, $locked->version);
            }

            $assembled = $this->assembler->assemble($locked, RuleSetStatus::Published);

            if ($assembled['rules'] === []) {
                throw RuleSetException::noRules();
            }

            $answer = $this->engine->run('validate-rule-set', ['ruleSet' => $assembled]);

            if (($answer['ok'] ?? false) !== true || ! is_string($answer['hash'] ?? null)) {
                /** @var list<array{property: string, reason: string}> $issues */
                $issues = is_array($answer['missing'] ?? null) ? array_values($answer['missing']) : [];

                throw RuleSetException::invalid($issues);
            }

            if ($expectedHash !== null && ! hash_equals($answer['hash'], $expectedHash)) {
                throw RuleSetException::changedSinceReview();
            }

            $locked->update(['status' => RuleSetStatus::Published, 'hash' => $answer['hash'], 'published_at' => now()]);

            return $locked;
        });
    }
}
