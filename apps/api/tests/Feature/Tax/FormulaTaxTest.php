<?php

namespace Tests\Feature\Tax;

use App\Actions\Formula\UpdateFormulaTaxAction;
use App\Enums\TaxMode;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TaxRegime;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The tax of a product (owner decision 2026-11-21): disabled, or a rate from config/tax.php with a dated, sourced regime. */
class FormulaTaxTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TaxRegimeSeeder::class);
        $this->formula = Formula::factory()->create(['code' => 'F-TAX', 'name' => 'Taxed product']);
        app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '100'],
        ], null);
    }

    private function saveTax(string $setting): TestResponse
    {
        return $this->from('/back')->put(route('formulas.tax.update', $this->formula), ['tax_setting' => $setting]);
    }

    public function test_a_new_product_has_no_tax_setting_and_the_editor_says_so(): void
    {
        $this->assertNull($this->formula->fresh()->tax_mode);

        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('tax.setting', null)
            ->where('tax.taxType', config('tax.type')));
    }

    public function test_the_editor_offers_disabled_and_every_configured_rate_and_nothing_else(): void
    {
        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(function (Assert $page): void {
            $values = array_column($page->toArray()['props']['tax']['options'], 'value');
            $this->assertSame(['disabled', ...array_map(fn (string $rate): string => 'rate:'.$rate, config('tax.allowed_rates'))], $values);
        });
    }

    public function test_the_options_follow_the_configuration_not_a_literal(): void
    {
        config(['tax.allowed_rates' => ['0', '14', '5']]);

        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(function (Assert $page): void {
            $this->assertSame(['disabled', 'rate:0', 'rate:14', 'rate:5'], array_column($page->toArray()['props']['tax']['options'], 'value'));
        });
    }

    public function test_a_rate_is_saved_on_the_formula_and_comes_back_in_the_editor(): void
    {
        $this->saveTax('rate:14')->assertSessionHas('success')->assertSessionHasNoErrors();

        $formula = $this->formula->fresh();
        $this->assertSame(TaxMode::Rate, $formula->tax_mode);
        $this->assertSame('14', rtrim(rtrim((string) $formula->tax_rate_pct, '0'), '.'));

        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(fn (Assert $page) => $page->where('tax.setting', 'rate:14'));
    }

    public function test_zero_percent_is_a_rate_and_is_not_the_same_as_disabled(): void
    {
        $this->saveTax('rate:0')->assertSessionHasNoErrors();
        $zero = $this->formula->fresh();
        $this->assertSame(TaxMode::Rate, $zero->tax_mode);
        $this->assertNotNull($zero->tax_rate_pct);

        $this->saveTax('disabled')->assertSessionHasNoErrors();
        $off = $this->formula->fresh();
        $this->assertSame(TaxMode::Disabled, $off->tax_mode);
        $this->assertNull($off->tax_rate_pct);
    }

    public function test_switching_to_disabled_drops_the_stored_rate(): void
    {
        $this->saveTax('rate:14');
        $this->saveTax('disabled');

        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(fn (Assert $page) => $page->where('tax.setting', 'disabled'));
        $this->assertNull($this->formula->fresh()->tax_rate_pct);
    }

    public function test_a_rate_that_is_not_allowed_or_a_made_up_option_is_refused(): void
    {
        foreach (['rate:15', 'rate:14.5', 'rate:', 'free', '14', ''] as $setting) {
            $this->saveTax($setting)->assertSessionHasErrors('tax_setting');
        }

        $this->assertNull($this->formula->fresh()->tax_mode);
    }

    public function test_the_action_refuses_a_rate_outside_the_configuration_and_a_missing_rate(): void
    {
        $action = app(UpdateFormulaTaxAction::class);

        $this->assertThrowsException(InvalidTaxSettingException::class, fn () => $action($this->formula, TaxMode::Rate, '15'));
        $this->assertThrowsException(InvalidTaxSettingException::class, fn () => $action($this->formula, TaxMode::Rate, null));
        $this->assertThrowsException(InvalidTaxSettingException::class, fn () => $action($this->formula, TaxMode::Rate, 'abc'));
        $this->assertNull($this->formula->fresh()->tax_mode);
    }

    public function test_the_action_accepts_an_allowed_rate_written_with_trailing_zeros(): void
    {
        app(UpdateFormulaTaxAction::class)($this->formula, TaxMode::Rate, '14.00');

        $this->assertSame(TaxMode::Rate, $this->formula->fresh()->tax_mode);
    }

    public function test_the_database_refuses_a_rate_mode_without_a_rate_and_a_rate_on_a_disabled_product(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => $this->formula->forceFill(['tax_mode' => 'rate', 'tax_rate_pct' => null])->save());
        $this->assertThrowsException(QueryException::class, fn () => $this->formula->forceFill(['tax_mode' => 'disabled', 'tax_rate_pct' => '14'])->save());
    }

    public function test_the_tax_setting_is_not_part_of_an_approved_version_so_it_can_change_after_approval(): void
    {
        FormulaVersion::query()->where('formula_id', $this->formula->id)->update(['status' => 'approved']);

        $this->saveTax('rate:14')->assertSessionHasNoErrors();

        $this->assertSame(TaxMode::Rate, $this->formula->fresh()->tax_mode);
    }

    public function test_the_editor_hands_the_engine_its_dated_regimes_with_source_and_pending_verification(): void
    {
        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(function (Assert $page): void {
            $tax = $page->toArray()['props']['tax'];
            $this->assertSame(now()->toDateString(), $tax['asOf']);
            $this->assertCount(count(config('tax.allowed_rates')), $tax['regimes']);

            foreach ($tax['regimes'] as $regime) {
                $this->assertSame($this->formula->id, $regime['productFamilyId']);
                $this->assertSame(config('tax.type'), $regime['taxType']);
                $this->assertSame(config('tax.regime.valid_from'), $regime['validFrom']);
                $this->assertSame(config('tax.regime.confidence'), $regime['confidence']);
                $this->assertNotEmpty($regime['sourceId']);
            }

            foreach ($tax['regimeInfo'] as $info) {
                $this->assertSame('pending', $info['verification']);
                $this->assertSame(config('tax.source.title'), $info['sourceTitle']);
            }
        });
    }

    public function test_a_closed_regime_keeps_its_end_date_for_the_engine(): void
    {
        TaxRegime::query()->where('rate_pct', '14')->update(['valid_to' => '2027-12-31']);

        $this->get(route('formulas.versions.edit', [$this->formula, 1]))->assertInertia(function (Assert $page): void {
            $fourteen = collect($page->toArray()['props']['tax']['regimes'])->firstWhere('ratePct', '14');
            $this->assertSame('2027-12-31', $fourteen['validTo']);
        });
    }
}
