<?php

use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

it('defaults to Arabic, right to left, with the system theme', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('locale.current', 'ar')
        ->where('locale.direction', 'rtl')
        ->where('theme.preference', 'system')
        ->where('appUrl', config('app.url'))
        ->where('translations', fn ($translations) => $translations['app.name'] === 'Formula Lab'
            && $translations['nav.dashboard'] === 'لوحة التحكم'));
});

it('renders the server-owned theme, language and direction on the html element', function () {
    $this->get('/')
        ->assertSee('<html lang="ar" dir="rtl"', false)
        ->assertSee('data-theme-source="server"', false)
        ->assertSee('data-theme-preference="system"', false);
});

it('saves a valid theme preference in a cookie and shares it back', function () {
    $this->from('/')->put('/settings/theme', ['preference' => 'dark'])
        ->assertRedirect('/')
        ->assertCookie('fl_theme', 'dark');

    $this->withCookie('fl_theme', 'dark')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('theme.preference', 'dark'))
        ->assertSee('data-mode="dark"', false)
        ->assertSee('data-theme-preference="dark"', false);
});

it('rejects an unknown theme preference', function () {
    $this->from('/')->put('/settings/theme', ['preference' => 'neon'])
        ->assertSessionHasErrors('preference')
        ->assertCookieMissing('fl_theme');
});

it('ignores a tampered theme cookie', function () {
    $this->withCookie('fl_theme', 'neon')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('theme.preference', 'system'));
});

it('switches to English left to right and shares English text', function () {
    $this->from('/')->put('/settings/locale', ['locale' => 'en'])
        ->assertRedirect('/')
        ->assertCookie('fl_locale', 'en');

    $this->withCookie('fl_locale', 'en')->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale.current', 'en')
            ->where('locale.direction', 'ltr')
            ->where('translations', fn ($translations) => $translations['nav.dashboard'] === 'Dashboard'))
        ->assertSee('<html lang="en" dir="ltr"', false);
});

it('rejects an unsupported locale', function () {
    $this->from('/')->put('/settings/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale')
        ->assertCookieMissing('fl_locale');
});

it('offers every configured locale to the switcher', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('locale.supported.0.code', 'ar')
        ->where('locale.supported.1.code', 'en'));
});

it('keeps the Arabic and English dictionaries in step', function () {
    foreach (config('ui.shared_groups') as $group) {
        $ar = array_keys(Arr::dot(trans($group, [], 'ar')));
        $en = array_keys(Arr::dot(trans($group, [], 'en')));

        sort($ar);
        sort($en);

        expect($ar)->not->toBe([])->and($ar)->toBe($en, "lang group [{$group}] differs between ar and en");
    }
});

it('renders the coming-soon page for sidebar sections that are not built yet', function () {
    foreach (config('ui.placeholder_sections') as $section) {
        $this->get("/{$section}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ComingSoon')->where('section', $section));
    }
});

it('does not turn unknown paths into placeholder pages', function () {
    $this->get('/not-a-section')->assertNotFound();
});

it('registers the list demo playground in the testing environment', function () {
    $this->get('/__dev/list-demo')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dev/ListDemo')->has('list.schema.filters'));
});
