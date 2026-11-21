<?php

namespace Tests\Feature\Tax;

use App\Enums\SourceKind;
use App\Models\Source;
use App\Models\TaxRegime;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxRegimeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_source_and_one_dated_regime_per_configured_rate_pending_verification(): void
    {
        $this->seed(TaxRegimeSeeder::class);

        $source = Source::query()->where('kind', SourceKind::OwnerDecision->value)->sole();
        $this->assertSame(config('tax.source.title'), $source->title);

        $regimes = TaxRegime::query()->orderBy('rate_pct')->get();
        $this->assertCount(count(config('tax.allowed_rates')), $regimes);

        foreach ($regimes as $regime) {
            $this->assertSame($source->id, $regime->source_id);
            $this->assertSame(config('tax.type'), $regime->tax_type);
            $this->assertSame('2026-11-21', $regime->valid_from->toDateString());
            $this->assertNull($regime->valid_to);
            $this->assertSame('L6_community', $regime->confidence);
            $this->assertTrue($regime->isPending());
        }
    }

    public function test_it_can_run_again_without_duplicating(): void
    {
        $this->seed(TaxRegimeSeeder::class);
        $this->seed(TaxRegimeSeeder::class);

        $this->assertSame(1, Source::query()->where('kind', SourceKind::OwnerDecision->value)->count());
        $this->assertSame(count(config('tax.allowed_rates')), TaxRegime::query()->count());
    }

    public function test_the_database_refuses_a_rate_above_100_and_a_window_that_ends_before_it_starts(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => TaxRegime::factory()->create(['rate_pct' => '101']));
        $this->assertThrowsException(QueryException::class, fn () => TaxRegime::factory()->create(['valid_from' => '2026-05-01', 'valid_to' => '2026-04-01']));
    }

    public function test_the_owner_decision_source_kind_has_a_label_and_is_not_offered_in_forms(): void
    {
        $this->assertNotSame('enums.source_kind.owner_decision', SourceKind::OwnerDecision->label());
        $this->assertNotContains('owner_decision', array_column(SourceKind::selectableOptions(), 'value'));
    }

    public function test_the_configured_rates_are_the_owners_zero_and_fourteen_as_decimal_strings(): void
    {
        $this->assertSame(['0', '14'], config('tax.allowed_rates'));
    }
}
