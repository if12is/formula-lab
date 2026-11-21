<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ListExchangeRatesRequest;
use App\Http\Requests\Settings\LookupExchangeRateRequest;
use App\Http\Requests\Settings\StoreExchangeRateRequest;
use App\Http\Requests\Settings\UpdateExchangeRateRequest;
use App\Models\ExchangeRate;
use App\Services\Currency\ExchangeRateListService;
use App\Services\Currency\ExchangeRateLookupService;
use App\Services\Currency\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ExchangeRateController extends Controller
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private ExchangeRateListService $exchangeRateListService,
        private ExchangeRateLookupService $exchangeRateLookupService,
    ) {}

    public function index(ListExchangeRatesRequest $request): InertiaResponse
    {
        return Inertia::render('Settings/ExchangeRates', [
            'list' => $this->exchangeRateListService->list($request),
            'latest' => $this->exchangeRateListService->latestByCurrency(),
            'prefill' => $request->prefill(),
            ...$this->exchangeRateListService->formProps(),
        ]);
    }

    public function store(StoreExchangeRateRequest $request): RedirectResponse
    {
        try {
            $this->exchangeRateService->record($request->validated(), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['rate_date' => $exception->getMessage()]);
        }

        return redirect()->route('settings.exchange-rates.index')->with('success', __('exchange_rates.added'));
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $rate): RedirectResponse
    {
        try {
            $this->exchangeRateService->update($rate, $request->validated());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['rate_date' => $exception->getMessage()]);
        }

        return back()->with('success', __('exchange_rates.updated'));
    }

    /** JSON: the rate that applies to a currency on a day, or `null` when there is none. */
    public function lookup(LookupExchangeRateRequest $request): JsonResponse
    {
        return response()->json([
            'rate' => $this->exchangeRateLookupService->rateOn($request->validated('currency'), $request->validated('date')),
        ]);
    }
}
