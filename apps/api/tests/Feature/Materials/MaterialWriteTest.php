<?php

namespace Tests\Feature\Materials;

use App\Enums\AuditAction;
use App\Enums\TradeMaterialStatus;
use App\Models\AuditLog;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialWriteTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'trade_name' => 'SLES',
            'grade' => '70%',
            'function' => 'Surfactant',
            'substance_id' => Substance::factory()->create()->id,
            'is_mixture' => false,
            'status' => 'draft',
            ...$overrides,
        ];
    }

    public function test_it_creates_a_material_and_opens_it(): void
    {
        $payload = $this->payload();

        $response = $this->post('/materials', $payload);

        $material = TradeMaterial::query()->where('trade_name', 'SLES')->firstOrFail();
        $response->assertRedirect(route('materials.show', $material))->assertSessionHas('success', __('materials.created'));
        $this->assertSame(TradeMaterialStatus::Draft, $material->status);
        $this->assertSame($payload['substance_id'], $material->substance_id);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'trade_materials', 'record_id' => $material->id, 'action' => AuditAction::Create->value]);
    }

    public function test_it_creates_a_mixture_without_a_substance(): void
    {
        $this->post('/materials', $this->payload(['is_mixture' => true, 'substance_id' => null, 'status' => 'active']))->assertSessionHasNoErrors();

        $material = TradeMaterial::query()->firstOrFail();
        $this->assertTrue($material->is_mixture);
        $this->assertNull($material->substance_id);
        $this->assertSame(TradeMaterialStatus::Active, $material->status);
    }

    public function test_it_refuses_a_missing_name_with_a_translated_message(): void
    {
        $this->post('/materials', $this->payload(['trade_name' => '']))
            ->assertSessionHasErrors(['trade_name' => __('validation.required', ['attribute' => __('validation.attributes.trade_name')])]);

        $this->assertDatabaseCount('trade_materials', 0);
    }

    public function test_it_refuses_an_unknown_status_and_an_unknown_substance(): void
    {
        $this->post('/materials', $this->payload(['status' => 'retired', 'substance_id' => fake()->uuid()]))
            ->assertSessionHasErrors(['status', 'substance_id']);
    }

    public function test_it_refuses_a_substance_on_a_mixture(): void
    {
        $this->post('/materials', $this->payload(['is_mixture' => true]))
            ->assertSessionHasErrors(['substance_id' => __('materials.errors.mixture_has_no_substance')]);
    }

    public function test_it_refuses_too_long_text(): void
    {
        $this->post('/materials', $this->payload(['trade_name' => str_repeat('a', 256)]))
            ->assertSessionHasErrors(['trade_name' => __('validation.max.string', ['attribute' => __('validation.attributes.trade_name'), 'max' => 255])]);
    }

    public function test_it_updates_a_material_and_the_audit_trail_records_the_change(): void
    {
        $material = TradeMaterial::factory()->create(['trade_name' => 'Old name', 'status' => TradeMaterialStatus::Draft]);

        $this->from('/materials/'.$material->id)
            ->put('/materials/'.$material->id, $this->payload(['trade_name' => 'New name', 'status' => 'active', 'substance_id' => null]))
            ->assertRedirect('/materials/'.$material->id)
            ->assertSessionHas('success', __('materials.updated'));

        $material->refresh();
        $this->assertSame('New name', $material->trade_name);
        $this->assertSame(TradeMaterialStatus::Active, $material->status);
        $this->assertNull($material->substance_id);

        $entry = AuditLog::query()->where('record_id', $material->id)->where('action', AuditAction::Update->value)->firstOrFail();
        $this->assertSame('Old name', $entry->before['trade_name']);
        $this->assertSame('New name', $entry->after['trade_name']);
    }

    public function test_update_validates_like_create(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->from('/materials/'.$material->id)
            ->put('/materials/'.$material->id, $this->payload(['trade_name' => '']))
            ->assertRedirect('/materials/'.$material->id)
            ->assertSessionHasErrors('trade_name');
    }

    public function test_an_unknown_or_malformed_material_id_is_not_found(): void
    {
        $this->get('/materials/'.fake()->uuid())->assertNotFound();
        $this->get('/materials/not-a-uuid')->assertNotFound();
        $this->put('/materials/'.fake()->uuid(), $this->payload())->assertNotFound();
    }
}
