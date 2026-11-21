<?php

namespace Tests\Feature\Sanad;

use App\Actions\Sanad\PublishFinishedGoodToSanadAction;
use App\Enums\TaxMode;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\Formula;
use App\Models\SanadOutbox;
use App\Models\Tenant;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\fakes\sanad\SanadFake;

/** The finished good a formula is published as carries the product's tax setting (disabled / 0% / rate) to Sanad. */
class FinishedGoodPublishTest extends SanadTestCase
{
    private const DETAILS = ['uom' => 'unit', 'cost_price' => '18.4573', 'sell_price' => '39.00'];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->seed(TaxRegimeSeeder::class);
    }

    private function formula(?string $mode, ?string $rate = null, array $attributes = []): Formula
    {
        return Formula::factory()->create($attributes + ['tenant_id' => $this->tenant->id, 'tax_mode' => $mode, 'tax_rate_pct' => $rate]);
    }

    private function publish(Formula $formula, array $details = self::DETAILS): array
    {
        $result = app(PublishFinishedGoodToSanadAction::class)($formula, $details, $this->device);

        return SanadOutbox::query()->findOrFail($result->outboxId)->payload;
    }

    public function test_fourteen_percent_is_sent_as_a_two_place_decimal_string(): void
    {
        $payload = $this->publish($this->formula('rate', '14'));

        $this->assertSame('14.00', $payload['tax_rate']);
        $this->assertSame('finished_good', $payload['item_kind']);
        $this->assertTrue($payload['is_sellable']);
        $this->assertSame('18.4573', $payload['cost_price']);
        $this->assertSame('39.00', $payload['sell_price']);
    }

    public function test_zero_percent_is_sent_as_zero_not_as_no_tax(): void
    {
        $payload = $this->publish($this->formula('rate', '0'));

        $this->assertArrayHasKey('tax_rate', $payload);
        $this->assertSame('0.00', $payload['tax_rate']);
    }

    public function test_a_disabled_tax_is_sent_as_no_rate_at_all(): void
    {
        $payload = $this->publish($this->formula('disabled'));

        $this->assertArrayHasKey('tax_rate', $payload);
        $this->assertNull($payload['tax_rate']);
    }

    public function test_a_product_with_no_tax_chosen_is_refused_and_nothing_is_queued(): void
    {
        $formula = $this->formula(null);

        $this->assertThrowsException(InvalidTaxSettingException::class, fn () => app(PublishFinishedGoodToSanadAction::class)($formula, self::DETAILS, $this->device));
        $this->assertSame(0, SanadOutbox::query()->count());
        $this->assertNull($formula->fresh()->sanad_product_uuid);
    }

    public function test_a_rate_without_a_regime_in_force_today_is_refused(): void
    {
        config(['tax.allowed_rates' => ['0', '14']]);
        $this->travelTo(now()->setDate(2020, 1, 1));

        $this->assertThrowsException(InvalidTaxSettingException::class, fn () => app(PublishFinishedGoodToSanadAction::class)($this->formula('rate', '14'), self::DETAILS, $this->device));
    }

    public function test_publishing_again_updates_the_same_sanad_product(): void
    {
        $formula = $this->formula('rate', '14');

        $first = app(PublishFinishedGoodToSanadAction::class)($formula, self::DETAILS, $this->device);
        $second = app(PublishFinishedGoodToSanadAction::class)($formula->fresh(), ['cost_price' => '19.00'] + self::DETAILS, $this->device);

        $this->assertSame($first->productUuid, $second->productUuid);
        $this->assertSame($first->productUuid, $formula->fresh()->sanad_product_uuid);
        $this->assertSame(2, SanadOutbox::query()->count());
    }

    public function test_the_payload_has_no_floats_and_names_the_formula(): void
    {
        $formula = $this->formula('rate', '14', ['code' => 'FG-1', 'name' => 'Premium Shampoo']);
        $payload = $this->publish($formula);

        $this->assertSame('Premium Shampoo', $payload['name']);
        $this->assertSame('FG-1', $payload['sku']);
        $this->assertSame($formula->id, $payload['external_id']);
        array_walk_recursive($payload, fn ($value) => $this->assertFalse(is_float($value)));
    }

    public function test_a_missing_unit_or_a_non_decimal_price_is_refused(): void
    {
        $formula = $this->formula('rate', '14');
        $action = app(PublishFinishedGoodToSanadAction::class);

        $this->assertThrowsException(SanadLinkException::class, fn () => $action($formula, ['uom' => ' '] + self::DETAILS, $this->device));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($formula, ['cost_price' => '18,4'] + self::DETAILS, $this->device));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($formula, ['sell_price' => '-1'] + self::DETAILS, $this->device));
    }

    public function test_another_tenants_device_cannot_publish_the_formula(): void
    {
        $formula = $this->formula('rate', '14', ['tenant_id' => Tenant::factory()->create()->id]);

        $this->assertThrowsException(SanadLinkException::class, fn () => app(PublishFinishedGoodToSanadAction::class)($formula, self::DETAILS, $this->device));
    }

    public function test_publishing_over_lan_sends_the_tax_rate_and_links_the_formula(): void
    {
        SanadFake::upsertProduct(status: 201);
        $formula = $this->formula('rate', '0');

        $result = app(PublishFinishedGoodToSanadAction::class)($formula, self::DETAILS);

        $this->assertSame('lan', $result->mode);
        $this->assertSame($result->productUuid, $formula->fresh()->sanad_product_uuid);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['tax_rate'] === '0.00' && ! isset($request['uuid']));
    }

    public function test_the_tax_mode_enum_has_a_translated_label(): void
    {
        $this->assertNotSame('enums.tax_mode.disabled', TaxMode::Disabled->label());
        $this->assertCount(2, TaxMode::options());
    }
}
