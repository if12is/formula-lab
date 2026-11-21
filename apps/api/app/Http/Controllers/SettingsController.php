<?php

namespace App\Http\Controllers;

use App\Support\UiPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function theme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preference' => ['required', 'string', Rule::in(config('ui.themes'))],
        ]);

        UiPreferences::rememberTheme($validated['preference']);

        return back();
    }

    public function locale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('ui.locales')))],
        ]);

        UiPreferences::rememberLocale($validated['locale']);

        return back();
    }
}
