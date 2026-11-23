<?php

namespace App\Http\Controllers\Terms;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTermsAcceptance;
use App\Http\Requests\Terms\AcceptTermsRequest;
use App\Services\Terms\TermsAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** S-16: the terms of use screen and the acceptance. */
class TermsController extends Controller
{
    public function __construct(private TermsAcceptanceService $termsService) {}

    public function show(Request $request): InertiaResponse
    {
        return Inertia::render('Terms/Show', [
            ...$this->termsService->screen($request->user(), $request->session()->getId()),
            'gated' => $request->session()->has(RequireTermsAcceptance::INTENDED_KEY),
            'urls' => ['accept' => route('terms.accept', [], false), 'home' => route('home', [], false)],
        ]);
    }

    public function accept(AcceptTermsRequest $request): RedirectResponse
    {
        try {
            $this->termsService->accept($request->user(), $request->session()->getId(), $request->version());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['version' => $exception->getMessage()]);
        }

        return redirect(RequireTermsAcceptance::pullIntended($request))->with('success', __('terms.accepted_flash'));
    }
}
