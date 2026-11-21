<?php

namespace App\Http\Requests\Compliance\Concerns;

use App\Enums\RuleOperator;
use App\Enums\RuleTargetType;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Support\DecimalCompare;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One rule in the engine's rules DSL, as the form sends it (the browser builds the JSON and validates it with the
 * engine first). This is the server's structural check of that JSON: shape, enums, decimal limit, source or pending.
 * The engine's own validator has the last word when the rule set is published.
 */
trait ValidatesRule
{
    use TranslatesValidationMessages;

    private const DECIMAL = '/^-?\d+(\.\d+)?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rule' => ['required', 'array:id,version,scope,target,operator,limit,unit,group,source,pendingSource,messageKey,labelWarningKey'],
            'rule.id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'rule.version' => ['required', 'string', 'max:50'],
            'rule.scope' => ['required', 'array:productTypes,rinseOff,ifraCategory'],
            'rule.scope.productTypes' => ['required', 'array', 'min:1', 'max:50'],
            'rule.scope.productTypes.*' => ['required', 'string', 'max:100'],
            'rule.scope.rinseOff' => ['nullable', 'boolean'],
            'rule.scope.ifraCategory' => ['nullable', 'string', 'max:50'],
            'rule.target' => ['required', 'array:'.implode(',', array_column(RuleTargetType::cases(), 'value'))],
            'rule.target.*' => ['required', 'string', 'max:255'],
            'rule.operator' => ['required', Rule::enum(RuleOperator::class)],
            'rule.limit' => ['nullable', 'string', 'regex:'.self::DECIMAL],
            'rule.unit' => ['required', 'string', 'max:20'],
            'rule.group' => ['nullable', 'string', 'max:100'],
            'rule.source' => ['nullable', 'array:sourceId,title,ref'],
            'rule.source.sourceId' => ['required_with:rule.source', 'uuid', 'exists:sources,id'],
            'rule.source.title' => ['nullable', 'string', 'max:255'],
            'rule.source.ref' => ['required_with:rule.source', 'string', 'max:255'],
            'rule.pendingSource' => ['nullable', 'boolean'],
            'rule.messageKey' => ['required', 'string', 'max:100'],
            'rule.labelWarningKey' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['rule', 'rule.target', 'rule.operator', 'rule.limit', 'rule.source'])) {
                return;
            }

            /** @var array<string, mixed> $rule */
            $rule = (array) $this->input('rule');
            $target = (array) ($rule['target'] ?? []);
            $operator = RuleOperator::tryFrom((string) ($rule['operator'] ?? ''));
            $limit = $rule['limit'] ?? null;
            $targetType = count($target) === 1 ? RuleTargetType::tryFrom((string) array_key_first($target)) : null;

            if ($targetType === null) {
                $validator->errors()->add('rule.target', __('compliance.errors.target_exactly_one'));
            }

            if ($operator !== null && $operator->takesLimit() && $limit === null) {
                $validator->errors()->add('rule.limit', __('compliance.errors.limit_required'));
            }

            if ($operator !== null && ! $operator->takesLimit() && $limit !== null) {
                $validator->errors()->add('rule.limit', __('compliance.errors.limit_must_be_empty'));
            }

            if ($targetType?->isConcentration() && ($rule['unit'] ?? null) !== '%w/w') {
                $validator->errors()->add('rule.unit', __('compliance.errors.concentration_unit'));
            }

            $hasSource = isset($rule['source']['sourceId']);
            $pending = (bool) ($rule['pendingSource'] ?? false);

            if ($hasSource === $pending) {
                $validator->errors()->add($hasSource ? 'rule.pendingSource' : 'rule.source', __($hasSource ? 'compliance.errors.source_and_pending' : 'compliance.errors.source_or_pending'));
            }

            if (($rule['group'] ?? null) !== null) {
                $positive = is_string($limit) && DecimalCompare::compare($limit, '0') > 0;

                if ($operator !== RuleOperator::LessOrEqual || ! $positive || $targetType?->isConcentration() !== true) {
                    $validator->errors()->add('rule.group', __('compliance.errors.group_invalid'));
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function ruleDocument(): array
    {
        return (array) $this->validated('rule');
    }

    public function ruleMessage(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    public function ruleActive(): bool
    {
        return (bool) $this->validated('active', true);
    }
}
