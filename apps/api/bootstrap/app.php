<?php

use App\Exceptions\Domain\SanadLinkException;
use App\Http\Middleware\AuthenticateMockSanad;
use App\Http\Middleware\AuthenticateSanadDevice;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTermsAcceptance;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Mock of Sanad for local development and e2e (G-10): no session, no CSRF, never in production.
            if (app()->environment('local', 'testing')) {
                Route::group([], __DIR__.'/../routes/mock-sanad.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'sanad.device' => AuthenticateSanadDevice::class,
            'idempotent' => EnsureIdempotency::class,
            'sanad.mock' => AuthenticateMockSanad::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            // S-16: after the Inertia middleware, so its redirect is an Inertia-aware one.
            RequireTermsAcceptance::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*', '__mock/*') || $request->expectsJson());

        $exceptions->render(function (SanadLinkException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], $exception->status);
            }
        });
    })->create();
