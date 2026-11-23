<?php

namespace App\Providers;

use App\Models\Substance;
use App\Models\TradeMaterial;
use App\Models\User;
use App\Services\Currency\Providers\ExchangeRateProvider;
use App\Services\Decisions\OverrideAuthorizer;
use App\Services\Production\ProductionAudience;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Fluent;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The source of exchange rates is a config choice (config/currencies.php), so a CBE provider is a drop-in.
        $this->app->bind(ExchangeRateProvider::class, fn ($app): ExchangeRateProvider => $app->make(config('currencies.providers')[config('currencies.provider')]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerExactDecimalColumn();
        $this->registerSanadRateLimiter();

        // Y-04: who may override a blocked decision (replaced by a real permission when RBAC lands, U-01).
        Gate::define('decisions.override', fn (User $user): bool => app(OverrideAuthorizer::class)->allows($user));

        // Q-01 / Q-11: who may approve a production order or a deviation above the threshold (replaced by a real permission at U-01).
        Gate::define('production.approve', fn (User $user): bool => app(ProductionAudience::class)->allowsApproval($user));

        // Stable, DB-visible names for polymorphic references (property_values.entity_type).
        Relation::morphMap([
            'substance' => Substance::class,
            'trade_material' => TradeMaterial::class,
        ]);
    }

    /**
     * `$table->exactDecimal('x')` creates an unconstrained PostgreSQL `numeric`: exact,
     * arbitrary precision, no implicit rounding (Laravel's decimal() forces a scale).
     */
    private function registerExactDecimalColumn(): void
    {
        Blueprint::macro('exactDecimal', function (string $column) {
            /** @var Blueprint $this */
            return $this->addColumn('exactDecimal', $column);
        });

        PostgresGrammar::macro('typeExactDecimal', fn (Fluent $column): string => 'numeric');
    }

    /** Limit for the inbound Sanad routes, per token (per IP while unauthenticated). */
    private function registerSanadRateLimiter(): void
    {
        RateLimiter::for('sanad', fn (Request $request): Limit => Limit::perMinute(config('sanad.inbound.requests_per_minute'))
            ->by($request->bearerToken() !== null ? hash('sha256', $request->bearerToken()) : (string) $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('sanad.throttled')], 429, $headers)));
    }
}
