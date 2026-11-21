<?php

namespace Tests\Feature\Sanad;

use App\Actions\Sanad\LinkTradeMaterialToSanadProductAction;
use App\Actions\Sanad\PublishMaterialToSanadAction;
use App\Enums\Sanad\SanadItemKind;
use App\Enums\Sanad\SanadOutboxKind;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\SanadOutbox;
use App\Models\SanadProduct;
use App\Models\SanadRef;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Sanad\SanadItemSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\fakes\sanad\SanadFake;

class SanadItemSyncTest extends SanadTestCase
{
    private SanadItemSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->sync = app(SanadItemSyncService::class);
    }

    private function material(array $attributes = []): TradeMaterial
    {
        return TradeMaterial::factory()->create($attributes + ['tenant_id' => $this->tenant->id]);
    }

    public function test_pull_caches_raw_materials_packaging_and_finished_goods_but_not_merchandise(): void
    {
        SanadFake::products(SanadFake::fixture('products'));

        $result = $this->sync->pull($this->tenant->id);

        $this->assertSame(0, $result->conflicts);
        $this->assertFalse($result->hasMore);
        $this->assertSame(4, SanadProduct::query()->where('deleted', false)->count());
        $this->assertSame(['raw_material', 'packaging', 'finished_good'], array_keys($result->cursors));
        $this->assertSame(0, SanadProduct::query()->where('item_kind', SanadItemKind::Merchandise)->count());
        $glycerin = SanadProduct::query()->findOrFail('11111111-1111-4111-8111-111111111111');
        $this->assertSame('41.20', $glycerin->cost_price);
        $this->assertSame('كجم', $glycerin->uom);
    }

    public function test_pull_follows_cursors_across_pages_and_is_idempotent(): void
    {
        $page1 = [SanadFake::product(['uuid' => '10000000-0000-4000-8000-000000000001']), SanadFake::product(['uuid' => '10000000-0000-4000-8000-000000000002'])];
        $page2 = [SanadFake::product(['uuid' => '10000000-0000-4000-8000-000000000003'])];
        SanadFake::productPages([$page1, $page2]);

        $first = $this->sync->pull($this->tenant->id);
        $second = $this->sync->pull($this->tenant->id);

        $this->assertSame(3, SanadProduct::query()->count());
        $this->assertSame(3, SanadRef::query()->count());
        $this->assertSame($first->accepted, $second->accepted);
        $this->assertSame(0, $second->conflicts);
        $this->assertSame('page-2', $first->cursors['raw_material']);
    }

    public function test_pull_applies_tombstones_and_reports_unidentifiable_rows(): void
    {
        $uuid = '10000000-0000-4000-8000-000000000001';
        SanadFake::products([SanadFake::product(['uuid' => $uuid])]);
        $this->sync->pull($this->tenant->id);

        SanadFake::products([SanadFake::tombstone($uuid, '2026-11-22T10:00:00Z', 'raw_material'), ['name' => 'no uuid', 'item_kind' => 'raw_material']]);
        $result = $this->sync->pull($this->tenant->id);

        $this->assertTrue(SanadProduct::query()->findOrFail($uuid)->deleted);
        $this->assertSame(1, $result->conflicts);
    }

    public function test_pull_stops_at_the_page_limit_and_says_there_is_more(): void
    {
        config(['sanad.client.max_pages_per_pull' => 1]);
        SanadFake::productPages([[SanadFake::product()], [SanadFake::product()]]);

        $result = $this->sync->pull($this->tenant->id);

        $this->assertTrue($result->hasMore);
        $this->assertSame(1, $result->pages);
    }

    public function test_pull_surfaces_sanad_errors_as_domain_exceptions(): void
    {
        SanadFake::fail('products', 401);

        $this->assertThrowsException(SanadIntegrationException::class, fn () => $this->sync->pull($this->tenant->id));
    }

    public function test_link_candidates_exclude_linked_deleted_and_other_tenants_products(): void
    {
        $free = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Free']);
        $linked = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id]);
        SanadProduct::factory()->deleted()->create(['tenant_id' => $this->tenant->id]);
        SanadProduct::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $fg = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id, 'item_kind' => SanadItemKind::FinishedGood]);
        $this->material(['sanad_product_uuid' => $linked->uuid]);

        $this->assertSame([$free->uuid], $this->sync->linkCandidates($this->tenant->id)->pluck('uuid')->all());
        $this->assertSame([], $this->sync->linkCandidates($this->tenant->id, SanadItemKind::Packaging)->all());
        $this->assertNotNull($fg);
    }

    public function test_linking_sets_the_soft_reference_and_the_ref_row(): void
    {
        $product = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id]);
        SanadRef::factory()->create(['tenant_id' => $this->tenant->id, 'sanad_uuid' => $product->uuid]);
        $material = $this->material();

        $linked = app(LinkTradeMaterialToSanadProductAction::class)($material, $product);

        $this->assertSame($product->uuid, $linked->fresh()->sanad_product_uuid);
        $ref = SanadRef::query()->where('sanad_uuid', $product->uuid)->firstOrFail();
        $this->assertSame($material->id, $ref->local_id);
        $this->assertSame('trade_materials', $ref->local_table);
    }

    public function test_linking_is_idempotent_for_the_same_pair(): void
    {
        $product = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id]);
        $material = $this->material(['sanad_product_uuid' => $product->uuid]);

        $this->assertSame($material->id, $this->sync->link($material, $product)->id);
    }

    public function test_linking_rules(): void
    {
        $action = app(LinkTradeMaterialToSanadProductAction::class);
        $product = SanadProduct::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['tenant_id' => Tenant::factory()->create()->id]), $product));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['tenant_id' => null]), $product));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(), SanadProduct::factory()->deleted()->create(['tenant_id' => $this->tenant->id])));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(), SanadProduct::factory()->create(['tenant_id' => $this->tenant->id, 'item_kind' => SanadItemKind::FinishedGood])));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['sanad_product_uuid' => fake()->uuid()]), $product));

        $action($this->material(), $product);
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(), $product));
    }

    public function test_publishing_through_the_link_queues_a_non_sellable_raw_material_and_links_it(): void
    {
        $material = $this->material(['trade_name' => 'SLES 70%']);

        $result = app(PublishMaterialToSanadAction::class)($material, 'kg', device: $this->device);

        $this->assertSame('outbox', $result->mode);
        $row = SanadOutbox::query()->findOrFail($result->outboxId);
        $this->assertSame(SanadOutboxKind::FinishedGood, $row->kind);
        $this->assertSame($result->productUuid, $row->external_id);
        $this->assertEquals([
            'uuid' => $result->productUuid, 'name' => 'SLES 70%', 'item_kind' => 'raw_material',
            'is_sellable' => false, 'uom' => 'kg', 'external_id' => $material->id,
        ], $row->payload);
        $this->assertSame($result->productUuid, $material->fresh()->sanad_product_uuid);
        Http::assertNothingSent();
    }

    public function test_publishing_over_lan_upserts_a_packaging_item(): void
    {
        SanadFake::upsertProduct(status: 201);
        $material = $this->material();

        $result = app(PublishMaterialToSanadAction::class)($material, 'unit', SanadItemKind::Packaging);

        $this->assertSame('lan', $result->mode);
        $this->assertSame('packaging', $result->response['item_kind']);
        $this->assertFalse($result->response['is_sellable']);
        $this->assertSame($result->productUuid, $material->fresh()->sanad_product_uuid);
        $this->assertSame(0, SanadOutbox::query()->count());
    }

    public function test_publishing_refuses_a_linked_material_a_missing_unit_and_non_material_kinds(): void
    {
        $action = app(PublishMaterialToSanadAction::class);

        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['sanad_product_uuid' => fake()->uuid()]), 'kg', device: $this->device));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(), '  ', device: $this->device));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(), 'kg', SanadItemKind::FinishedGood, $this->device));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['tenant_id' => null]), 'kg'));
        $this->assertThrowsException(SanadLinkException::class, fn () => $action($this->material(['tenant_id' => Tenant::factory()->create()->id]), 'kg', device: $this->device));
        $this->assertSame(0, SanadOutbox::query()->count());
    }

    public function test_a_failed_lan_publish_leaves_the_material_unlinked(): void
    {
        SanadFake::fail('upsertProduct', 422, 'bad', ['errors' => ['uom' => ['required']]]);
        $material = $this->material();

        $this->assertThrowsException(SanadIntegrationException::class, fn () => app(PublishMaterialToSanadAction::class)($material, 'kg'));
        $this->assertNull($material->fresh()->sanad_product_uuid);
    }
}
