<?php

namespace Tests\Feature\Fragrance;

use App\Enums\Confidence;
use App\Exceptions\Domain\IfraCertificateException;
use App\Models\IfraCertificate;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\SanadSupplier;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Fragrance\FragranceProfileService;
use App\Services\Fragrance\IfraCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** L-01: the IFRA certificate of a fragrance oil, the limits read from it, and what the oil still lacks. */
class IfraCertificateTest extends TestCase
{
    use RefreshDatabase;

    private TradeMaterial $oil;

    private Source $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oil = TradeMaterial::factory()->create(['is_fragrance' => true]);
        $this->document = Source::factory()->create(['title' => 'Certificate scan']);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return ['certificate_number' => 'TEST-CERT-1', 'ifra_amendment' => 'A-TEST', 'issuer' => 'Test lab', 'issued_on' => '2026-03-01', 'source_id' => $this->document->getKey(), 'confidence' => 'L1_coa_batch', ...$override];
    }

    public function test_it_adds_a_certificate_with_its_document_and_confidence(): void
    {
        $this->post(route('materials.ifra-certificates.store', $this->oil), $this->payload())
            ->assertRedirect(route('materials.show', ['material' => $this->oil, 'tab' => 'fragrance']));

        $certificate = IfraCertificate::query()->firstOrFail();
        $this->assertSame($this->oil->getKey(), $certificate->trade_material_id);
        $this->assertSame('TEST-CERT-1', $certificate->certificate_number);
        $this->assertSame(Confidence::L1CoaBatch, $certificate->confidence);
        $this->assertSame('2026-03-01', $certificate->issued_on->toDateString());
    }

    public function test_a_certificate_needs_a_number_a_date_a_document_and_a_confidence_and_numbers_are_unique_per_oil(): void
    {
        $this->post(route('materials.ifra-certificates.store', $this->oil), [])->assertSessionHasErrors(['certificate_number', 'issued_on', 'source_id', 'confidence']);

        $this->post(route('materials.ifra-certificates.store', $this->oil), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('materials.ifra-certificates.store', $this->oil), $this->payload())->assertSessionHasErrors('certificate_number');
        $this->post(route('materials.ifra-certificates.store', TradeMaterial::factory()->create()), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_a_limit_takes_its_source_confidence_and_date_from_the_certificate(): void
    {
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey(), 'source_id' => $this->document->getKey(), 'confidence' => Confidence::L2SupplierTds, 'issued_on' => '2026-02-10']);
        $other = Source::factory()->create();

        $this->post("/materials/{$this->oil->getKey()}/properties", [
            'property' => 'ifra_limit', 'category' => 'Cat X', 'value' => '1.2', 'unit' => '%w/w', 'ifra_certificate_id' => $certificate->getKey(),
            'source_id' => $other->getKey(), 'confidence' => 'L1_coa_batch', 'valid_from' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $limit = PropertyValue::query()->where('property', 'ifra_limit')->firstOrFail();
        $this->assertSame($this->document->getKey(), $limit->source_id);
        $this->assertSame(Confidence::L2SupplierTds, $limit->confidence);
        $this->assertSame('2026-02-10', $limit->valid_from->toDateString());
    }

    public function test_a_limit_cannot_cite_the_certificate_of_another_oil_or_repeat_a_category(): void
    {
        $foreign = IfraCertificate::factory()->create();
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey(), 'source_id' => $this->document->getKey()]);
        $limit = ['property' => 'ifra_limit', 'category' => 'Cat X', 'value' => '1.2', 'unit' => '%w/w', 'source_id' => $this->document->getKey(), 'confidence' => 'L1_coa_batch', 'valid_from' => '2026-03-01'];

        $this->post("/materials/{$this->oil->getKey()}/properties", [...$limit, 'ifra_certificate_id' => $foreign->getKey()])->assertSessionHasErrors('ifra_certificate_id');
        $this->post("/materials/{$this->oil->getKey()}/properties", [...$limit, 'ifra_certificate_id' => $certificate->getKey()])->assertSessionHasNoErrors();
        $this->post("/materials/{$this->oil->getKey()}/properties", [...$limit, 'ifra_certificate_id' => $certificate->getKey(), 'value' => '0.5'])->assertSessionHasErrors('category');

        $this->assertSame(1, PropertyValue::query()->where('property', 'ifra_limit')->count());
    }

    public function test_a_certificate_with_limits_cannot_be_removed_and_one_without_can(): void
    {
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey()]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->oil->getKey(), 'property' => 'ifra_limit', 'category' => 'Cat X', 'unit' => '%w/w', 'ifra_certificate_id' => $certificate->getKey()]);

        $this->expectException(IfraCertificateException::class);
        try {
            app(IfraCertificateService::class)->remove($certificate);
        } finally {
            $this->assertModelExists($certificate);
        }
    }

    public function test_the_controller_reports_a_blocked_removal_and_removes_a_free_certificate(): void
    {
        $used = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey()]);
        $free = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey()]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->oil->getKey(), 'property' => 'ifra_limit', 'category' => 'Cat X', 'unit' => '%w/w', 'ifra_certificate_id' => $used->getKey()]);

        $this->delete(route('materials.ifra-certificates.destroy', [$this->oil, $used]))->assertSessionHas('error');
        $this->delete(route('materials.ifra-certificates.destroy', [$this->oil, $free]))->assertRedirect();

        $this->assertModelExists($used);
        $this->assertModelMissing($free);
    }

    public function test_flash_point_is_a_registered_property_in_celsius(): void
    {
        $this->post("/materials/{$this->oil->getKey()}/properties", ['property' => 'flash_point', 'value' => '70', 'unit' => '°C', 'source_id' => $this->document->getKey(), 'confidence' => 'L2_supplier_tds', 'valid_from' => '2026-03-01'])
            ->assertSessionHasNoErrors();
        $this->post("/materials/{$this->oil->getKey()}/properties", ['property' => 'flash_point', 'value' => '70', 'unit' => 'K', 'source_id' => $this->document->getKey(), 'confidence' => 'L2_supplier_tds', 'valid_from' => '2026-03-01'])
            ->assertSessionHasErrors('unit');
    }

    public function test_the_profile_lists_what_is_missing_and_nothing_is_complete_by_default(): void
    {
        $profile = app(FragranceProfileService::class)->profile($this->oil);

        $this->assertSame(array_fill(0, 6, false), array_column($profile['checks'], 'present'));
        $this->assertSame(FragranceProfileService::CHECKS, array_column($profile['checks'], 'key'));
        $this->assertFalse($profile['complete']);
        $this->assertSame([], $profile['certificates']);
        $this->assertNull($profile['supplier']);
    }

    public function test_the_profile_is_complete_with_a_supplier_a_certificate_a_limit_a_density_a_flash_point_and_a_price(): void
    {
        $supplier = SanadSupplier::factory()->create(['name' => 'Test supplier']);
        $this->oil->update(['sanad_supplier_uuid' => $supplier->uuid]);
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $this->oil->getKey(), 'source_id' => $this->document->getKey(), 'certificate_number' => 'C-9']);
        foreach ([['ifra_limit', '1.2', '%w/w', 'Cat X'], ['density', '0.9', 'g/mL', null], ['flash_point', '70', '°C', null]] as [$property, $value, $unit, $category]) {
            PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->oil->getKey(), 'property' => $property, 'value' => $value, 'unit' => $unit, 'category' => $category, 'ifra_certificate_id' => $property === 'ifra_limit' ? $certificate->getKey() : null]);
        }
        Price::factory()->create(['trade_material_id' => $this->oil->getKey()]);

        $profile = app(FragranceProfileService::class)->profile($this->oil);

        $this->assertTrue($profile['complete']);
        $this->assertSame('Test supplier', $profile['supplier']);
        $this->assertSame('C-9', $profile['certificates'][0]['certificate_number']);
        $this->assertSame([['category' => 'Cat X', 'value' => '1.2', 'unit' => '%w/w']], array_map(fn (array $limit): array => array_intersect_key($limit, array_flip(['category', 'value', 'unit'])), $profile['certificates'][0]['limits']));
    }

    public function test_the_material_page_carries_the_profile_for_a_fragrance_oil_only_and_has_a_fragrance_tab(): void
    {
        $this->get(route('materials.show', ['material' => $this->oil, 'tab' => 'fragrance']))
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'fragrance')->where('fragrance.complete', false)->has('fragrance.checks', 6));

        $plain = TradeMaterial::factory()->create(['is_fragrance' => false]);
        $this->get(route('materials.show', $plain))->assertInertia(fn (Assert $page) => $page->where('fragrance', null));
    }
}
