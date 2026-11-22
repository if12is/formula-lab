<?php

namespace App\Http\Controllers\Formula;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Exceptions\Domain\FinishedGoodPublishBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Costing\PublishFinishedGoodRequest;
use App\Models\Formula;
use App\Services\Costing\FinishedGoodPublishService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** G-09: publishing a formula's finished good to Sanad, gated by the approved cost and the publish-to-sanad decision rule set. */
class FormulaPublishController extends Controller
{
    public function __construct(private FinishedGoodPublishService $publishService) {}

    public function show(Request $request, Formula $formula): InertiaResponse
    {
        return Inertia::render('Formulas/Publish', $this->publishService->panel($formula, $request->user(), $request->only('sell_price')));
    }

    public function store(PublishFinishedGoodRequest $request, Formula $formula): RedirectResponse
    {
        try {
            $publication = $this->publishService->publish($formula, $request->details(), $request->user());
        } catch (FinishedGoodPublishBlockedException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('sanad_publish.published_'.$publication->mode));
    }
}
