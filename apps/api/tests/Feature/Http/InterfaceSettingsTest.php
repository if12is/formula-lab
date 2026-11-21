<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InterfaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_defaults_to_arabic_right_to_left_with_the_system_theme(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('locale.current', 'ar')
            ->where('locale.direction', 'rtl')
            ->where('theme.preference', 'system')
            ->where('appUrl', config('app.url'))
            ->where('translations', fn ($translations) => $translations['app.name'] === 'Formula Lab'
                && $translations['nav.dashboard'] === 'لوحة التحكم'));
    }

    public function test_it_renders_the_server_owned_theme_language_and_direction_on_the_html_element(): void
    {
        $this->get('/')
            ->assertSee('<html lang="ar" dir="rtl"', false)
            ->assertSee('data-theme-source="server"', false)
            ->assertSee('data-theme-preference="system"', false);
    }

    public function test_it_saves_a_valid_theme_preference_in_a_cookie_and_shares_it_back(): void
    {
        $this->from('/')->put('/settings/theme', ['preference' => 'dark'])
            ->assertRedirect('/')
            ->assertCookie('fl_theme', 'dark');

        $this->withCookie('fl_theme', 'dark')->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('theme.preference', 'dark'))
            ->assertSee('data-mode="dark"', false)
            ->assertSee('data-theme-preference="dark"', false);
    }

    public function test_it_rejects_an_unknown_theme_preference(): void
    {
        $this->from('/')->put('/settings/theme', ['preference' => 'neon'])
            ->assertSessionHasErrors('preference')
            ->assertCookieMissing('fl_theme');
    }

    public function test_it_ignores_a_tampered_theme_cookie(): void
    {
        $this->withCookie('fl_theme', 'neon')->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('theme.preference', 'system'));
    }

    public function test_it_switches_to_english_left_to_right_and_shares_english_text(): void
    {
        $this->from('/')->put('/settings/locale', ['locale' => 'en'])
            ->assertRedirect('/')
            ->assertCookie('fl_locale', 'en');

        $this->withCookie('fl_locale', 'en')->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale.current', 'en')
                ->where('locale.direction', 'ltr')
                ->where('translations', fn ($translations) => $translations['nav.dashboard'] === 'Dashboard'))
            ->assertSee('<html lang="en" dir="ltr"', false);
    }

    public function test_it_rejects_an_unsupported_locale(): void
    {
        $this->from('/')->put('/settings/locale', ['locale' => 'fr'])
            ->assertSessionHasErrors('locale')
            ->assertCookieMissing('fl_locale');
    }

    public function test_it_offers_every_configured_locale_to_the_switcher(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('locale.supported.0.code', 'ar')
            ->where('locale.supported.1.code', 'en'));
    }

    public function test_it_keeps_the_arabic_and_english_dictionaries_in_step(): void
    {
        foreach (config('ui.shared_groups') as $group) {
            $ar = array_keys(Arr::dot(trans($group, [], 'ar')));
            $en = array_keys(Arr::dot(trans($group, [], 'en')));

            sort($ar);
            sort($en);

            $this->assertNotSame([], $ar);
            $this->assertSame($ar, $en, "lang group [{$group}] differs between ar and en");
        }
    }

    public function test_it_renders_the_coming_soon_page_for_sidebar_sections_that_are_not_built_yet(): void
    {
        foreach (config('ui.placeholder_sections') as $section) {
            $this->get("/{$section}")->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('ComingSoon')->where('section', $section));
        }
    }

    public function test_it_does_not_turn_unknown_paths_into_placeholder_pages(): void
    {
        $this->get('/not-a-section')->assertNotFound();
    }

    public function test_it_registers_the_list_demo_playground_in_the_testing_environment(): void
    {
        $this->get('/__dev/list-demo')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dev/ListDemo')->has('list.schema.filters'));
    }

    public function test_it_flashes_a_translated_success_message_when_the_theme_is_saved(): void
    {
        $this->from('/')->put('/settings/theme', ['preference' => 'dark'])
            ->assertSessionHas('success', __('settings.theme_saved', [], 'ar'));
    }

    public function test_it_words_the_locale_confirmation_in_the_newly_chosen_language(): void
    {
        $this->from('/')->put('/settings/locale', ['locale' => 'en'])
            ->assertSessionHas('success', __('settings.locale_saved', [], 'en'));
    }

    public function test_it_translates_validation_errors_and_attribute_names(): void
    {
        $this->from('/')->put('/settings/theme', ['preference' => 'neon'])
            ->assertSessionHasErrors(['preference' => __('validation.in', ['attribute' => __('validation.attributes.preference')])]);

        $this->from('/')->put('/settings/locale', [])
            ->assertSessionHasErrors(['locale' => __('validation.required', ['attribute' => __('validation.attributes.locale')])]);
    }
}
