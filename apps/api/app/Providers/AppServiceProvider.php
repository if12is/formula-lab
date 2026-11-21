<?php

namespace App\Providers;

use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerExactDecimalColumn();
        $this->registerSanadRateLimiter();

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
