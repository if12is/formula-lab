<?php

namespace App\Http\Requests\Packaging;

use App\Enums\Confidence;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use App\Models\PackagingCompatibility;
use App\Support\Packaging\NeckFinish;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A neck finish pair a person declares compatible (H-02): both finishes, and the source and confidence of the statement. */
class StorePackagingCompatibilityRequest extends FormRequest
{
    use TranslatesValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'container_neck_finish' => ['required', 'string', 'max:100'],
            'closure_neck_finish' => ['required', 'string', 'max:100'],
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'confidence' => ['required', Rule::enum(Confidence::class)],
            'note' => ['nullable', 'string', 'max:'.config('lab.max_note_length')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['container_neck_finish', 'closure_neck_finish'])) {
                return;
            }

            $container = NeckFinish::key((string) $this->input('container_neck_finish'));
            $closure = NeckFinish::key((string) $this->input('closure_neck_finish'));

            if ($container === '' || $closure === '') {
                $validator->errors()->add($container === '' ? 'container_neck_finish' : 'closure_neck_finish', __('validation.required'));

                return;
            }

            $declared = PackagingCompatibility::query()->get(['container_neck_finish', 'closure_neck_finish'])
                ->contains(fn (PackagingCompatibility $pair): bool => NeckFinish::key($pair->container_neck_finish) === $container && NeckFinish::key($pair->closure_neck_finish) === $closure);

            if ($declared) {
                $validator->errors()->add('closure_neck_finish', __('packaging.errors.pair_declared'));
            }
        });
    }

    /**
     * @return array{container_neck_finish: string, closure_neck_finish: string, source_id: string, confidence: string, note: string|null}
     */
    public function pairData(): array
    {
        $data = $this->validated();

        return [
            'container_neck_finish' => trim($data['container_neck_finish']),
            'closure_neck_finish' => trim($data['closure_neck_finish']),
            'source_id' => $data['source_id'],
            'confidence' => $data['confidence'],
            'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
        ];
    }
}
