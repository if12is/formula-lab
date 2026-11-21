<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateLocaleRequest;
use App\Http\Requests\Settings\UpdateThemeRequest;
use App\Services\Settings\UiPreferenceService;
use Illuminate\Http\RedirectResponse;

class SettingsController extends Controller
{
    public function __construct(
        private UiPreferenceService $uiPreferenceService,
    ) {}

    public function theme(UpdateThemeRequest $request): RedirectResponse
    {
        $this->uiPreferenceService->updateTheme($request->validated('preference'));

        return back()->with('success', __('settings.theme_saved'));
    }

    public function locale(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');
        $this->uiPreferenceService->updateLocale($locale);

        // The new locale only applies from the next request, so the confirmation is worded in it now.
        return back()->with('success', __('settings.locale_saved', [], $locale));
    }
}
