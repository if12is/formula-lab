<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Models\IfraCertificate;
use App\Models\MaterialComposition;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialCompositionService;
use App\Services\Material\MaterialOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The Composition tab of a material and the shape the compliance engine reads it in. */
class MaterialCompositionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(Source $source, array $override = []): array
    {
        return ['cas' => 'TEST-0001', 'inci' => 'TEST INGREDIENT', 'pct_ww' => '2.5', 'source_id' => $source->getKey(), 'confidence' => 'L1_coa_batch', 'valid_from' => '2026-03-01', ...$override];
    }

    public function test_it_adds_a_component_with_its_source_and_lists_it_on_the_tab(): void
    {
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create(['title' => 'Batch certificate']);

        $this->post("/materials/{$material->getKey()}/composition", $this->payload($source))
            ->assertRedirect(route('materials.show', ['material' => $material, 'tab' => 'composition']))
            ->assertSessionHas('success');

        $this->get("/materials/{$material->getKey()}?tab=composition")->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'composition')
            ->has('composition', 1)
            ->where('composition.0.cas', 'TEST-0001')
            ->where('composition.0.inci', 'TEST INGREDIENT')
            ->where('composition.0.pct_ww', '2.5')
            ->where('composition.0.source_title', 'Batch certificate')
            ->where('composition.0.confidence', 'L1_coa_batch')
            ->where('composition.0.valid_from', '2026-03-01')
            ->where('material.composition_complete', false)
            ->where('material.is_fragrance', false));
    }

    public function test_the_percentage_is_kept_exact_and_shown_without_trailing_zeros(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->post("/materials/{$material->getKey()}/composition", $this->payload(Source::factory()->create(), ['pct_ww' => '0.100000000001']));
        $this->post("/materials/{$material->getKey()}/composition", $this->payload(Source::factory()->create(), ['cas' => 'TEST-0002', 'pct_ww' => '12.50']));

        $rows = app(MaterialCompositionService::class)->rows($material);
        $this->assertSame(['0.100000000001', '12.5'], array_column($rows, 'pct_ww'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): array
    {
        return [
            'no identifier' => [['cas' => null, 'inci' => null], 'cas'],
            'blank identifiers' => [['cas' => '  ', 'inci' => ''], 'cas'],
            'cas with spaces' => [['cas' => 'TEST 0001'], 'cas'],
            'share above 100' => [['pct_ww' => '100.5'], 'pct_ww'],
            'share is not a decimal' => [['pct_ww' => 'half'], 'pct_ww'],
            'negative share' => [['pct_ww' => '-1'], 'pct_ww'],
            'no source' => [['source_id' => null], 'source_id'],
            'unknown source' => [['source_id' => '00000000-0000-0000-0000-000000000000'], 'source_id'],
            'no confidence' => [['confidence' => null], 'confidence'],
            'bad confidence' => [['confidence' => 'L9'], 'confidence'],
            'no date' => [['valid_from' => null], 'valid_from'],
            'bad date' => [['valid_from' => '01/03/2026'], 'valid_from'],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('invalid')]
    public function test_it_rejects_an_invalid_component(array $override, string $errorKey): void
    {
        $material = TradeMaterial::factory()->create();

        $this->post("/materials/{$material->getKey()}/composition", $this->payload(Source::factory()->create(), $override))->assertSessionHasErrors($errorKey);

        $this->assertSame(0, MaterialComposition::query()->count());
    }

    public function test_a_share_of_exactly_100_is_accepted(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->post("/materials/{$material->getKey()}/composition", $this->payload(Source::factory()->create(), ['pct_ww' => '100']))->assertSessionHasNoErrors();
    }

    public function test_it_updates_and_removes_a_component_of_its_own_material_only(): void
    {
        $material = TradeMaterial::factory()->create();
        $other = TradeMaterial::factory()->create();
        $component = MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'pct_ww' => '1']);
        $source = Source::factory()->create();

        $this->put("/materials/{$material->getKey()}/composition/{$component->getKey()}", $this->payload($source, ['pct_ww' => '3']))->assertSessionHasNoErrors();
        $this->assertSame('3', $component->fresh()->pct_ww);

        $this->put("/materials/{$other->getKey()}/composition/{$component->getKey()}", $this->payload($source))->assertNotFound();
        $this->delete("/materials/{$other->getKey()}/composition/{$component->getKey()}")->assertNotFound();

        $this->delete("/materials/{$material->getKey()}/composition/{$component->getKey()}")->assertSessionHas('success');
        $this->assertSame(0, $material->compositions()->count());
    }

    public function test_the_completeness_and_fragrance_flags_are_saved(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->put("/materials/{$material->getKey()}/composition-flags", ['composition_complete' => true])->assertSessionHasNoErrors();
        $this->put("/materials/{$material->getKey()}/composition-flags", ['is_fragrance' => true])->assertSessionHasNoErrors();

        $material->refresh();
        $this->assertTrue($material->composition_complete);
        $this->assertTrue($material->is_fragrance);

        $this->put("/materials/{$material->getKey()}/composition-flags", ['composition_complete' => 'maybe'])->assertSessionHasErrors('composition_complete');
    }

    public function test_composition_changes_are_audited_and_shown_in_the_material_audit_trail(): void
    {
        $material = TradeMaterial::factory()->create();
        $component = MaterialComposition::factory()->create(['trade_material_id' => $material->getKey()]);
        $component->update(['pct_ww' => '5']);
        $component->delete();

        $this->get("/materials/{$material->getKey()}?tab=audit")->assertInertia(function (Assert $page): void {
            $summary = array_map(fn (array $entry): string => $entry['table'].':'.$entry['action'], $page->toArray()['props']['audit']);

            $this->assertContains('material_compositions:create', $summary);
            $this->assertContains('material_compositions:update', $summary);
            $this->assertContains('material_compositions:delete', $summary);
        });
    }

    public function test_the_engine_composition_of_a_material_without_components_is_null_never_empty(): void
    {
        $material = TradeMaterial::factory()->create();

        $payloads = app(MaterialCompositionService::class)->enginePayloads(collect([$material]));

        $this->assertArrayHasKey($material->getKey(), $payloads);
        $this->assertNull($payloads[$material->getKey()]);
    }

    public function test_the_engine_composition_carries_exact_shares_and_the_completeness_flag(): void
    {
        $material = TradeMaterial::factory()->create(['composition_complete' => true]);
        $source = Source::factory()->create();
        MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'cas' => 'TEST-0001', 'inci' => null, 'pct_ww' => '2.50', 'source_id' => $source->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-02-01']);
        MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'cas' => null, 'inci' => 'AQUA', 'pct_ww' => '97.5', 'source_id' => $source->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-02-01']);

        $payload = app(MaterialCompositionService::class)->enginePayloads(collect([$material]))[$material->getKey()];

        $this->assertSame([['cas' => 'TEST-0001', 'pctWW' => '2.5'], ['inci' => 'AQUA', 'pctWW' => '97.5']], $payload['components']);
        $this->assertTrue($payload['complete']);
        $this->assertSame($source->getKey(), $payload['sourceId']);
        $this->assertSame('L1_coa_batch', $payload['confidence']);
        $this->assertSame('2026-02-01', $payload['validFrom']);
    }

    public function test_several_sources_are_described_by_the_weakest_link_and_the_latest_date(): void
    {
        $material = TradeMaterial::factory()->create();
        $strong = Source::factory()->create();
        $weak = Source::factory()->create();
        MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'source_id' => $strong->getKey(), 'confidence' => Confidence::L1CoaBatch, 'valid_from' => '2026-05-01']);
        MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'source_id' => $weak->getKey(), 'confidence' => Confidence::L6Community, 'valid_from' => '2026-01-01']);

        $payload = app(MaterialCompositionService::class)->enginePayloads(collect([$material]))[$material->getKey()];

        $this->assertSame($weak->getKey(), $payload['sourceId']);
        $this->assertSame('L6_community', $payload['confidence']);
        $this->assertSame('2026-05-01', $payload['validFrom']);
    }

    public function test_the_material_options_the_editor_uses_carry_the_composition_and_the_ifra_limits(): void
    {
        $material = TradeMaterial::factory()->create(['is_fragrance' => true]);
        $source = Source::factory()->create();
        MaterialComposition::factory()->create(['trade_material_id' => $material->getKey(), 'source_id' => $source->getKey()]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->getKey(), 'property' => 'ifra_limit', 'category' => 'Cat X', 'value' => '1.2', 'unit' => '%w/w', 'source_id' => $source->getKey(), 'confidence' => Confidence::L1CoaBatch]);
        $plain = TradeMaterial::factory()->create();

        $options = app(MaterialOptionService::class)->forMaterials(collect([$material, $plain]));

        $this->assertTrue($options[$material->getKey()]['is_fragrance']);
        $this->assertNotNull($options[$material->getKey()]['composition']);
        $this->assertSame('Cat X', $options[$material->getKey()]['ifra_limits'][0]['category']);
        $this->assertSame('1.2', $options[$material->getKey()]['ifra_limits'][0]['value']);
        $this->assertNull($options[$plain->getKey()]['composition']);
        $this->assertSame([], $options[$plain->getKey()]['ifra_limits']);
        $this->assertFalse($options[$plain->getKey()]['is_fragrance']);
    }

    public function test_an_ifra_limit_needs_a_category_a_certificate_and_other_properties_ignore_both(): void
    {
        $material = TradeMaterial::factory()->create();
        $source = Source::factory()->create();
        $certificate = IfraCertificate::factory()->create(['trade_material_id' => $material->getKey(), 'source_id' => $source->getKey()]);
        $base = ['unit' => '%w/w', 'source_id' => $source->getKey(), 'confidence' => 'L1_coa_batch', 'valid_from' => '2026-03-01', 'value' => '1.2'];

        $this->post("/materials/{$material->getKey()}/properties", [...$base, 'property' => 'ifra_limit', 'ifra_certificate_id' => $certificate->getKey()])->assertSessionHasErrors('category');
        $this->post("/materials/{$material->getKey()}/properties", [...$base, 'property' => 'ifra_limit', 'category' => 'Cat X'])->assertSessionHasErrors('ifra_certificate_id');
        $this->post("/materials/{$material->getKey()}/properties", [...$base, 'property' => 'ifra_limit', 'category' => 'Cat X', 'ifra_certificate_id' => $certificate->getKey()])->assertSessionHasNoErrors();
        $this->post("/materials/{$material->getKey()}/properties", [...$base, 'property' => 'purity', 'category' => 'ignored', 'ifra_certificate_id' => $certificate->getKey()])->assertSessionHasNoErrors();

        $limit = PropertyValue::query()->where('property', 'ifra_limit')->firstOrFail();
        $this->assertSame('Cat X', $limit->category);
        $this->assertSame($certificate->getKey(), $limit->ifra_certificate_id);
        $purity = PropertyValue::query()->where('property', 'purity')->firstOrFail();
        $this->assertNull($purity->category);
        $this->assertNull($purity->ifra_certificate_id);
    }

    public function test_the_composition_tab_is_one_of_the_tabs(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->get("/materials/{$material->getKey()}?tab=composition")->assertInertia(fn (Assert $page) => $page->where('tab', 'composition'));
    }
}
