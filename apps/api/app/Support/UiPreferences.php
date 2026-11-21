<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The interface preferences a visitor has saved (theme, locale, display currency), read from cookies.
 * One place for the cookie names, the allowed values and the defaults, so the
 * middleware and the shared Inertia props agree. Saving goes through UiPreferenceService.
 */
final class UiPreferences
{
    public static function theme(Request $request): string
    {
        $saved = $request->cookie(config('ui.cookies.theme'));

        return is_string($saved) && in_array($saved, config('ui.themes'), true) ? $saved : 'system';
    }

    public static function locale(Request $request): string
    {
        $saved = $request->cookie(config('ui.cookies.locale'));

        return is_string($saved) && self::isSupportedLocale($saved) ? $saved : config('app.locale');
    }

    /** The currency costs are shown in next to EGP; the base currency means no second figure. */
    public static function displayCurrency(Request $request): string
    {
        $saved = $request->cookie(config('ui.cookies.display_currency'));

        return is_string($saved) && Currencies::isSupported($saved) ? $saved : Currencies::base();
    }

    public static function isSupportedLocale(string $locale): bool
    {
        return array_key_exists($locale, config('ui.locales'));
    }

    public static function direction(string $locale): string
    {
        return config("ui.locales.{$locale}.direction", 'ltr');
    }
}
