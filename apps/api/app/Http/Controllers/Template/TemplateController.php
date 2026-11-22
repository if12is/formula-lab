<?php

namespace App\Http\Controllers\Template;

use App\Exceptions\Domain\TemplateUseRejectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Template\UseTemplateRequest;
use App\Models\FormulaTemplate;
use App\Services\Template\TemplateDetailService;
use App\Services\Template\TemplateListService;
use App\Services\Template\TemplateUseService;
use App\Support\Families\EngineReasonText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TemplateController extends Controller
{
    public function __construct(
        private TemplateListService $templateListService,
        private TemplateDetailService $templateDetailService,
        private TemplateUseService $templateUseService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Templates/Index', ['list' => $this->templateListService->list($request)]);
    }

    public function show(FormulaTemplate $template): InertiaResponse
    {
        return Inertia::render('Templates/Show', $this->templateDetailService->forTemplate($template));
    }

    /** Starts a draft formula from the template, after the engine validated it and the compliance engine had no FAIL. */
    public function use(UseTemplateRequest $request, FormulaTemplate $template): RedirectResponse
    {
        try {
            $created = $this->templateUseService->use($template, $request->templateData(), $request->user());
        } catch (TemplateUseRejectedException $exception) {
            return back()->withErrors($this->errorsOf($exception))->withInput();
        }

        return redirect()
            ->route('formulas.versions.edit', [$created['formula'], $created['version']->version])
            ->with('success', __('templates.used'));
    }

    /**
     * The engine's gaps as form errors: a missing material or share lands on its own line, everything else on the form.
     *
     * @return array<string, string>
     */
    private function errorsOf(TemplateUseRejectedException $exception): array
    {
        $errors = [];

        foreach ($exception->gaps as $gap) {
            $text = EngineReasonText::of($gap['reason']);

            if (preg_match('/^line:(\w+)$/', $gap['property'], $match) === 1) {
                $field = str_ends_with($gap['reason'], 'percent_missing') ? 'percent_ww' : 'material_id';
                $errors["fill.{$match[1]}.{$field}"] = $text;

                continue;
            }

            $errors['template'] = isset($errors['template']) ? $errors['template'].' '.$text : $text;
        }

        return $errors === [] ? ['template' => $exception->getMessage()] : $errors;
    }
}
