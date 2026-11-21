<?php

return [
    /*
    | Locales the interface offers. The first entry is the default (see APP_LOCALE).
    | `direction` drives <html dir>; `native` is the name shown in the switcher.
    */
    'locales' => [
        'ar' => ['direction' => 'rtl', 'native' => 'العربية', 'short' => 'ع'],
        'en' => ['direction' => 'ltr', 'native' => 'English', 'short' => 'EN'],
    ],

    /* Theme preferences a person can save (the kit's modes). */
    'themes' => ['system', 'light', 'dark'],

    /* Cookie names holding the preferences until users get stored settings. */
    'cookies' => [
        'theme' => 'fl_theme',
        'locale' => 'fl_locale',
    ],

    /* Lifetime of those cookies, in minutes (one year). */
    'cookie_minutes' => 60 * 24 * 365,

    /*
    | lang/<locale>/<group>.php files shared with the frontend as one flat
    | dictionary ("group.key"). Server-only groups (validation, auth) stay out.
    */
    'shared_groups' => ['app', 'nav', 'common', 'filters', 'theme', 'locale', 'dashboard', 'errors', 'enums', 'dev', 'engine', 'materials', 'formulas'],

    /* Sidebar destinations that render the ComingSoon page until they are built. */
    'placeholder_sections' => ['calculators', 'compliance', 'lab', 'production', 'settings'],
];
