<?php

namespace App\Http\Middleware;

use App\Support\Translations;
use App\Support\UiPreferences;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'appUrl' => config('app.url'),
            'locale' => [
                'current' => $locale,
                'direction' => UiPreferences::direction($locale),
                'supported' => collect(config('ui.locales'))
                    ->map(fn (array $meta, string $code): array => ['code' => $code, 'native' => $meta['native'], 'short' => $meta['short']])
                    ->values()
                    ->all(),
            ],
            'theme' => ['preference' => UiPreferences::theme($request)],
            // The whole dictionary travels once per locale; later visits leave it out.
            'translations' => Inertia::once(fn (): array => Translations::for($locale))->as("translations.{$locale}"),
        ];
    }
}
