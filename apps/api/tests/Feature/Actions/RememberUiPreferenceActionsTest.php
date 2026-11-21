<?php

namespace Tests\Feature\Actions;

use App\Actions\Settings\RememberDisplayCurrencyPreferenceAction;
use App\Actions\Settings\RememberLocalePreferenceAction;
use App\Actions\Settings\RememberNavLayoutPreferenceAction;
use App\Actions\Settings\RememberThemePreferenceAction;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class RememberUiPreferenceActionsTest extends TestCase
{
    public function test_it_queues_the_theme_cookie_with_the_configured_lifetime(): void
    {
        app(RememberThemePreferenceAction::class)('dark');

        $cookie = Cookie::queued(config('ui.cookies.theme'));

        $this->assertNotNull($cookie);
        $this->assertSame('dark', $cookie->getValue());
        $this->assertEqualsWithDelta(time() + config('ui.cookie_minutes') * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_it_queues_the_navigation_layout_cookie_with_the_configured_lifetime(): void
    {
        app(RememberNavLayoutPreferenceAction::class)('topbar');

        $cookie = Cookie::queued(config('ui.cookies.nav_layout'));

        $this->assertNotNull($cookie);
        $this->assertSame('topbar', $cookie->getValue());
        $this->assertEqualsWithDelta(time() + config('ui.cookie_minutes') * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_it_queues_the_locale_cookie_with_the_configured_lifetime(): void
    {
        app(RememberLocalePreferenceAction::class)('en');

        $cookie = Cookie::queued(config('ui.cookies.locale'));

        $this->assertNotNull($cookie);
        $this->assertSame('en', $cookie->getValue());
        $this->assertEqualsWithDelta(time() + config('ui.cookie_minutes') * 60, $cookie->getExpiresTime(), 5);
    }

    public function test_it_queues_the_display_currency_cookie_with_the_configured_lifetime(): void
    {
        app(RememberDisplayCurrencyPreferenceAction::class)('USD');

        $cookie = Cookie::queued(config('ui.cookies.display_currency'));

        $this->assertNotNull($cookie);
        $this->assertSame('USD', $cookie->getValue());
        $this->assertEqualsWithDelta(time() + config('ui.cookie_minutes') * 60, $cookie->getExpiresTime(), 5);
    }
}
