<?php

namespace App\Actions\Settings;

use Illuminate\Support\Facades\Cookie;

final class RememberLocalePreferenceAction
{
    public function __invoke(string $locale): void
    {
        Cookie::queue(config('ui.cookies.locale'), $locale, config('ui.cookie_minutes'));
    }
}
