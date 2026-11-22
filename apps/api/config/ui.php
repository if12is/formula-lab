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

    /* Where the navigation goes: a sidebar or along the top (the kit's navLayout). */
    'nav_layouts' => ['sidebar', 'topbar'],

    /* The layout used until a person chooses one. */
    'default_nav_layout' => 'sidebar',

    /* Cookie names holding the preferences until users get stored settings. */
    'cookies' => [
        'theme' => 'fl_theme',
        'locale' => 'fl_locale',
        'nav_layout' => 'fl_nav_layout',
        'display_currency' => 'fl_display_currency',
    ],

    /* Lifetime of those cookies, in minutes (one year). */
    'cookie_minutes' => 60 * 24 * 365,

    /*
    | lang/<locale>/<group>.php files shared with the frontend as one flat
    | dictionary ("group.key"). Server-only groups (validation, auth) stay out.
    */
    'shared_groups' => ['app', 'nav', 'common', 'filters', 'theme', 'locale', 'dashboard', 'errors', 'enums', 'dev', 'engine', 'materials', 'formulas', 'properties', 'calculators', 'currencies', 'exchange_rates', 'compliance', 'tax', 'lab', 'price_lists', 'packaging', 'cost_report', 'sanad_publish', 'families', 'templates', 'fragrance', 'disinfection', 'decisions'],

    /* Sidebar destinations that render the ComingSoon page until they are built. */
    'placeholder_sections' => ['production', 'settings'],
];
