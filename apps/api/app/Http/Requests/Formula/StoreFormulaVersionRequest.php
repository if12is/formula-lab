<?php

namespace App\Http\Requests\Formula;

use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Http\Requests\Formula\Concerns\ValidatesFormulaLines;
use App\Support\DecimalCompare;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** The edited lines of a formula, saved as its next version with a reason and the q.s. balancing events. */
class StoreFormulaVersionRequest extends FormRequest
{
    use TranslatesValidationMessages, ValidatesFormulaLines;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.config('formula.max_reason_length')],
            'balance_events' => ['nullable', 'array', 'max:20'],
            'balance_events.*.material_id' => ['required', 'uuid'],
            'balance_events.*.before' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,9})?$/'],
            'balance_events.*.after' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,9})?$/'],
            ...$this->lineRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateLines($validator);
            $this->validateBalanceEvents($validator);
        });
    }

    /**
     * @return list<array{material_id: string, before: string|null, after: string}>
     */
    public function balanceEvents(): array
    {
        return array_map(fn (array $event): array => [
            'material_id' => $event['material_id'],
            'before' => $event['before'] ?? null,
            'after' => $event['after'],
        ], array_values($this->validated('balance_events') ?? []));
    }

    /** A balancing event is recorded only for a q.s. line of this version, with the share the line really carries. */
    private function validateBalanceEvents(Validator $validator): void
    {
        if (! $validator->errors()->isEmpty()) {
            return;
        }

        $qsShares = [];
        foreach ((array) $this->input('lines', []) as $line) {
            if ($line['is_qs'] ?? false) {
                $qsShares[$line['trade_material_id']] = $line['percent_ww'] ?? null;
            }
        }

        foreach ((array) $this->input('balance_events', []) as $index => $event) {
            $share = $qsShares[$event['material_id']] ?? null;

            if ($share === null || DecimalCompare::compare($share, $event['after']) !== 0) {
                $validator->errors()->add("balance_events.{$index}.after", __('formulas.errors.balance_event_mismatch'));
            }
        }
    }
}
