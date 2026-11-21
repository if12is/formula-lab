<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The interface preferences a visitor has saved (theme, locale), read from cookies.
 * One place for the cookie names, the allowed values and the defaults, so the
 * middleware, the controllers and the shared Inertia props agree.
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

    public static function isSupportedLocale(string $locale): bool
    {
        return array_key_exists($locale, config('ui.locales'));
    }

    public static function direction(string $locale): string
    {
        return config("ui.locales.{$locale}.direction", 'ltr');
    }

    public static function rememberTheme(string $theme): void
    {
        Cookie::queue(config('ui.cookies.theme'), $theme, config('ui.cookie_minutes'));
    }

    public static function rememberLocale(string $locale): void
    {
        Cookie::queue(config('ui.cookies.locale'), $locale, config('ui.cookie_minutes'));
    }
}
