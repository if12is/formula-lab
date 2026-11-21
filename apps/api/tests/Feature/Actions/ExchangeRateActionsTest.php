<?php

namespace Tests\Feature\Actions;

use App\Actions\Currency\CreateExchangeRateAction;
use App\Actions\Currency\UpdateExchangeRateAction;
use App\Exceptions\Domain\ExchangeRateException;
use App\Models\ExchangeRate;
use App\Models\Source;
use App\Models\User;
use App\Services\Currency\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateActionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{from_currency: string, rate: string, rate_date: string, source_id: string, confidence: string} */
    private function data(array $overrides = []): array
    {
        return [
            'from_currency' => 'USD', 'rate' => '48.5', 'rate_date' => '2026-11-20',
            'source_id' => Source::factory()->create()->id, 'confidence' => 'L4_regulatory_db', ...$overrides,
        ];
    }

    public function test_create_inserts_a_global_rate_into_the_base_currency_and_stamps_the_author(): void
    {
        $user = User::factory()->create();

        $rate = app(CreateExchangeRateAction::class)($this->data(), $user->id);

        $this->assertSame('EGP', $rate->to_currency);
        $this->assertNull($rate->tenant_id);
        $this->assertSame($user->id, $rate->created_by);
        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_create_always_targets_the_base_currency_whatever_it_is_given(): void
    {
        $rate = app(CreateExchangeRateAction::class)($this->data() + ['to_currency' => 'USD']);

        $this->assertSame('EGP', $rate->to_currency);
    }

    public function test_update_changes_the_fields_and_keeps_the_identity(): void
    {
        $rate = ExchangeRate::factory()->create(['rate' => '48']);

        $updated = app(UpdateExchangeRateAction::class)($rate, $this->data(['rate' => '49', 'from_currency' => 'EUR']));

        $this->assertSame($rate->id, $updated->id);
        $this->assertSame('49', $rate->fresh()->rate);
        $this->assertSame('EUR', $rate->fresh()->from_currency);
    }

    public function test_the_service_is_the_only_door_that_refuses_the_base_currency(): void
    {
        $this->assertThrowsException(ExchangeRateException::class, fn () => app(ExchangeRateService::class)->record($this->data(['from_currency' => 'EGP'])), 'EGP');
    }

    public function test_the_service_refuses_an_unsupported_currency_with_a_translated_message(): void
    {
        $this->assertThrowsException(ExchangeRateException::class, fn () => app(ExchangeRateService::class)->record($this->data(['from_currency' => 'JPY'])), __('exceptions.exchange_rate_unsupported_currency', ['currency' => 'JPY']));
    }

    public function test_the_service_refuses_a_duplicate_with_a_translated_message(): void
    {
        $data = $this->data();
        app(ExchangeRateService::class)->record($data);

        $this->assertThrowsException(ExchangeRateException::class, fn () => app(ExchangeRateService::class)->record($data), __('exceptions.exchange_rate_duplicate', ['currency' => 'USD', 'date' => '2026-11-20']));
    }
}
