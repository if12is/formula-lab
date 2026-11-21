<?php

namespace App\Actions\Settings;

use Illuminate\Support\Facades\Cookie;

final class RememberThemePreferenceAction
{
    public function __invoke(string $theme): void
    {
        Cookie::queue(config('ui.cookies.theme'), $theme, config('ui.cookie_minutes'));
    }
}
