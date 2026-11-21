<?php

namespace Tests\Feature\Currency;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateWriteTest extends TestCase
{
    use RefreshDatabase;

    private Source $cbe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cbe = Source::factory()->create(['title' => 'CBE']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'from_currency' => 'USD',
            'rate' => '48.5',
            'rate_date' => '2026-11-20',
            'source_id' => $this->cbe->id,
            'confidence' => 'L4_regulatory_db',
            ...$overrides,
        ];
    }

    public function test_it_seeds_no_rates_at_all(): void
    {
        $this->seed();

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_it_adds_a_rate_into_egp_with_its_source_and_confidence(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.exchange-rates.store'), $this->payload())
            ->assertRedirect(route('settings.exchange-rates.index'))
            ->assertSessionHas('success', __('exchange_rates.added'));

        $rate = ExchangeRate::query()->sole();
        $this->assertSame('USD', $rate->from_currency);
        $this->assertSame('EGP', $rate->to_currency);
        $this->assertSame('48.5', $rate->rate);
        $this->assertSame('2026-11-20', $rate->rate_date->toDateString());
        $this->assertSame($this->cbe->id, $rate->source_id);
        $this->assertSame('L4_regulatory_db', $rate->confidence->value);
        $this->assertNull($rate->tenant_id);
        $this->assertSame($user->id, $rate->created_by);
    }

    public function test_it_keeps_the_rate_exact_and_drops_trailing_zeros(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload(['rate' => '48.50000000']))->assertSessionHasNoErrors();

        $this->assertSame('48.5', ExchangeRate::query()->sole()->rate);

        $this->post(route('settings.exchange-rates.store'), $this->payload(['from_currency' => 'EUR', 'rate' => '0.00000001']))->assertSessionHasNoErrors();

        $this->assertSame('0.00000001', ExchangeRate::query()->where('from_currency', 'EUR')->sole()->rate);
    }

    public function test_it_records_the_creation_in_the_audit_trail(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload());
        $rate = ExchangeRate::query()->sole();

        $row = AuditLog::query()->where('table_name', 'exchange_rates')->where('record_id', $rate->id)->sole();
        $this->assertSame(AuditAction::Create, $row->action);
        $this->assertSame('USD', $row->after['from_currency']);
        $this->assertSame('48.5', $row->after['rate']);
    }

    public function test_it_updates_a_rate_and_the_audit_trail_records_before_and_after(): void
    {
        $user = User::factory()->create();
        $rate = ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '48.5', 'rate_date' => '2026-11-20', 'source_id' => $this->cbe->id]);

        $this->actingAs($user)->put(route('settings.exchange-rates.update', $rate), $this->payload(['rate' => '49.1']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('exchange_rates.updated'));

        $this->assertSame('49.1', $rate->fresh()->rate);

        $row = AuditLog::query()->where('table_name', 'exchange_rates')->where('record_id', $rate->id)->where('action', AuditAction::Update)->sole();
        $this->assertSame(['rate' => '48.5'], $row->before);
        $this->assertSame(['rate' => '49.1'], $row->after);
        $this->assertSame($user->id, $row->actor_id);
    }

    public function test_a_rate_can_be_saved_again_unchanged_without_clashing_with_itself(): void
    {
        $rate = ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '48.5', 'rate_date' => '2026-11-20', 'source_id' => $this->cbe->id]);

        $this->put(route('settings.exchange-rates.update', $rate), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_it_refuses_a_second_rate_for_the_same_currency_date_and_source(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload())->assertSessionHasNoErrors();

        $this->post(route('settings.exchange-rates.store'), $this->payload(['rate' => '50']))
            ->assertSessionHasErrors(['rate_date' => __('exceptions.exchange_rate_duplicate', ['currency' => 'USD', 'date' => '2026-11-20'])]);

        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_the_same_day_may_have_a_rate_from_another_source_or_for_another_currency(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('settings.exchange-rates.store'), $this->payload(['source_id' => Source::factory()->create()->id, 'rate' => '49']))->assertSessionHasNoErrors();
        $this->post(route('settings.exchange-rates.store'), $this->payload(['from_currency' => 'EUR', 'rate' => '52']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('exchange_rates', 3);
    }

    public function test_an_edit_cannot_collide_with_another_rate(): void
    {
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate_date' => '2026-11-20', 'source_id' => $this->cbe->id]);
        $other = ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate_date' => '2026-11-19', 'source_id' => $this->cbe->id]);

        $this->put(route('settings.exchange-rates.update', $other), $this->payload())->assertSessionHasErrors('rate_date');

        $this->assertSame('2026-11-19', $other->fresh()->rate_date->toDateString());
    }

    public function test_it_refuses_the_base_currency_and_unsupported_currencies(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload(['from_currency' => 'EGP']))
            ->assertSessionHasErrors(['from_currency' => __('validation.in', ['attribute' => __('validation.attributes.from_currency')])]);
        $this->post(route('settings.exchange-rates.store'), $this->payload(['from_currency' => 'JPY']))->assertSessionHasErrors('from_currency');
        $this->post(route('settings.exchange-rates.store'), $this->payload(['from_currency' => 'usd']))->assertSessionHasErrors('from_currency');

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_it_refuses_missing_fields_with_translated_messages(): void
    {
        $this->post(route('settings.exchange-rates.store'), [])
            ->assertSessionHasErrors([
                'from_currency' => __('validation.required', ['attribute' => __('validation.attributes.from_currency')]),
                'rate' => __('validation.required', ['attribute' => __('validation.attributes.rate')]),
                'rate_date' => __('validation.required', ['attribute' => __('validation.attributes.rate_date')]),
                'source_id' => __('validation.required', ['attribute' => __('validation.attributes.source_id')]),
                'confidence' => __('validation.required', ['attribute' => __('validation.attributes.confidence')]),
            ]);
    }

    public function test_it_refuses_a_rate_that_is_zero_negative_or_not_a_plain_decimal(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload(['rate' => '0']))
            ->assertSessionHasErrors(['rate' => __('exchange_rates.errors.rate_positive')]);
        $this->post(route('settings.exchange-rates.store'), $this->payload(['rate' => '0.000']))->assertSessionHasErrors(['rate' => __('exchange_rates.errors.rate_positive')]);

        foreach (['-1', '48,5', '4e1', 'abc', '1.123456789', '1234567890123'] as $bad) {
            $this->post(route('settings.exchange-rates.store'), $this->payload(['rate' => $bad]))->assertSessionHasErrors('rate');
        }

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_it_refuses_a_bad_date_an_unknown_source_and_an_unknown_confidence(): void
    {
        $this->post(route('settings.exchange-rates.store'), $this->payload(['rate_date' => '20/11/2026']))->assertSessionHasErrors('rate_date');
        $this->post(route('settings.exchange-rates.store'), $this->payload(['source_id' => fake()->uuid()]))->assertSessionHasErrors('source_id');
        $this->post(route('settings.exchange-rates.store'), $this->payload(['confidence' => 'L9']))->assertSessionHasErrors('confidence');

        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_the_database_refuses_a_rate_into_anything_but_egp(): void
    {
        $this->expectException(QueryException::class);

        ExchangeRate::factory()->create(['to_currency' => 'USD']);
    }

    public function test_the_database_refuses_a_zero_rate(): void
    {
        $this->expectException(QueryException::class);

        ExchangeRate::factory()->create(['rate' => '0']);
    }

    public function test_it_answers_404_for_an_unknown_rate(): void
    {
        $this->put(route('settings.exchange-rates.update', ['rate' => fake()->uuid()]), $this->payload())->assertNotFound();
    }
}
