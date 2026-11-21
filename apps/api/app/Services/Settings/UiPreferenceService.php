<?php

namespace App\Services\Settings;

use App\Actions\Settings\RememberDisplayCurrencyPreferenceAction;
use App\Actions\Settings\RememberLocalePreferenceAction;
use App\Actions\Settings\RememberNavLayoutPreferenceAction;
use App\Actions\Settings\RememberThemePreferenceAction;

/** Saves the interface preferences a visitor picked (cookies until users get stored settings). */
final class UiPreferenceService
{
    public function __construct(
        private RememberThemePreferenceAction $rememberTheme,
        private RememberNavLayoutPreferenceAction $rememberNavLayout,
        private RememberLocalePreferenceAction $rememberLocale,
        private RememberDisplayCurrencyPreferenceAction $rememberDisplayCurrency,
    ) {}

    public function updateTheme(string $theme): void
    {
        ($this->rememberTheme)($theme);
    }

    public function updateNavLayout(string $layout): void
    {
        ($this->rememberNavLayout)($layout);
    }

    public function updateLocale(string $locale): void
    {
        ($this->rememberLocale)($locale);
    }

    public function updateDisplayCurrency(string $currency): void
    {
        ($this->rememberDisplayCurrency)($currency);
    }
}
