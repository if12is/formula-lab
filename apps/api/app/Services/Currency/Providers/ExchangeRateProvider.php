<?php

namespace App\Services\Currency\Providers;

use App\Support\DTOs\ExchangeRateQuote;

/**
 * Where exchange rates come from. The extension point for automatic fetching (CBE, C-05): implement this, list the class
 * under `providers` in config/currencies.php and select it with `provider`. ExchangeRateService::importFrom() stores what
 * a provider returns through the one write path, so a provider never touches the database itself.
 */
interface ExchangeRateProvider
{
    /** The key under `providers` in config/currencies.php. */
    public function key(): string;

    /**
     * The rates the provider can supply for a day (YYYY-MM-DD), one per currency at most. Empty when it has none:
     * a provider never guesses or carries an older rate forward.
     *
     * @return list<ExchangeRateQuote>
     */
    public function fetch(string $date): array;
}
