<?php

namespace App\Actions\Settings;

use Illuminate\Support\Facades\Cookie;

final class RememberNavLayoutPreferenceAction
{
    public function __invoke(string $layout): void
    {
        Cookie::queue(config('ui.cookies.nav_layout'), $layout, config('ui.cookie_minutes'));
    }
}
