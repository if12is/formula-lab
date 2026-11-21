<?php

namespace App\Services\Settings;

use App\Actions\Settings\RememberLocalePreferenceAction;
use App\Actions\Settings\RememberThemePreferenceAction;

/** Saves the interface preferences a visitor picked (cookies until users get stored settings). */
final class UiPreferenceService
{
    public function __construct(
        private RememberThemePreferenceAction $rememberTheme,
        private RememberLocalePreferenceAction $rememberLocale,
    ) {}

    public function updateTheme(string $theme): void
    {
        ($this->rememberTheme)($theme);
    }

    public function updateLocale(string $locale): void
    {
        ($this->rememberLocale)($locale);
    }
}
