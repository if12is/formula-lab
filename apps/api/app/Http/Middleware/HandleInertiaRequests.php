<?php

namespace App\Http\Middleware;

use App\Enums\Assistant\AssistantDataCategory;
use App\Services\Currency\DisplayCurrencyService;
use App\Services\Security\FieldVisibilityService;
use App\Support\PropertyRegistry;
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
    public function __construct(private DisplayCurrencyService $displayCurrencyService, private FieldVisibilityService $fieldVisibility) {}

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
            // U-07: which field groups this visitor was sent; a page leaves out what it was not sent (the keys themselves are removed server-side).
            'fieldAudience' => fn (): array => $this->fieldVisibility->audience($request->user()),
            'locale' => [
                'current' => $locale,
                'direction' => UiPreferences::direction($locale),
                'supported' => collect(config('ui.locales'))
                    ->map(fn (array $meta, string $code): array => ['code' => $code, 'native' => $meta['native'], 'short' => $meta['short']])
                    ->values()
                    ->all(),
            ],
            // Flashed messages are translated where they are flashed; the layout shows them as toasts.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            // The known material properties (key, label key, units): selectors and labels read this one list.
            'propertyRegistry' => fn (): array => PropertyRegistry::options(),
            'theme' => ['preference' => UiPreferences::theme($request), 'navLayout' => UiPreferences::navLayout($request)],
            // The base currency, the visitor's display currency and the newest rate for it (the second figure next to EGP).
            'currency' => fn (): array => $this->displayCurrencyService->props(UiPreferences::displayCurrency($request)),
            // The assistant panel (P-10): off unless config assistant.enabled; its endpoints and the data categories it asks consent for.
            'assistant' => fn (): array => [
                'enabled' => (bool) config('assistant.enabled'),
                'askUrl' => route('assistant.ask', [], false),
                'consentUrl' => route('assistant.consent.show', [], false),
                'categories' => AssistantDataCategory::options(),
                'maxMessageChars' => (int) config('assistant.max_message_chars'),
            ],
            // The whole dictionary travels once per locale; later visits leave it out.
            'translations' => Inertia::once(fn (): array => Translations::for($locale))->as("translations.{$locale}"),
        ];
    }
}
