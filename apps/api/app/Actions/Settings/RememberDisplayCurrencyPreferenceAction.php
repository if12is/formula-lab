<?php

namespace App\Actions\Settings;

use Illuminate\Support\Facades\Cookie;

final class RememberDisplayCurrencyPreferenceAction
{
    public function __invoke(string $currency): void
    {
        Cookie::queue(config('ui.cookies.display_currency'), $currency, config('ui.cookie_minutes'));
    }
}
