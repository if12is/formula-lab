<?php

namespace Tests\Feature\Sanad;

use App\Enums\Sanad\SanadRefType;
use App\Models\SanadDevice;
use App\Models\SanadProduct;
use App\Models\SanadPurchaseLine;
use App\Models\SanadRef;
use App\Models\SanadSupplier;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Tests\fakes\sanad\SanadFake;

class SanadChangesTest extends SanadTestCase
{
    private const P1 = '11111111-1111-4111-8111-111111111111';

    private const S1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const PU1 = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    public function test_it_applies_products_suppliers_and_purchases_into_refs_and_caches(): void
    {
        $product = SanadFake::product(['uuid' => self::P1, 'cost_price' => '41.2000']);
        $supplier = SanadFake::supplier(['uuid' => self::S1, 'name_en' => 'Chem']);
        $purchase = SanadFake::purchase(['uuid' => self::PU1, 'supplier_uuid' => self::S1], [
            SanadFake::purchaseItem(['uuid' => 'e0000000-0000-4000-8000-000000000001', 'product_uuid' => self::P1, 'unit_cost' => '1287.5000']),
            SanadFake::purchaseItem(['uuid' => 'e0000000-0000-4000-8000-000000000002', 'product_uuid' => self::P1]),
        ]);

        $this->push([
            $this->item('supplier', self::S1, $supplier),
            $this->item('product', self::P1, $product),
            $this->item('purchase', self::PU1, $purchase),
        ])->assertOk()->assertJson(['accepted' => 3, 'cursor' => 'cursor-1', 'conflicts' => [], 'skipped' => []]);

        $cached = SanadProduct::query()->findOrFail(self::P1);
        $this->assertSame('41.2000', $cached->cost_price);
        $this->assertSame('kg', $cached->uom);
        $this->assertSame($this->tenant->id, $cached->tenant_id);
        $this->assertSame('Chem', SanadSupplier::query()->findOrFail(self::S1)->name_en);

        $lines = SanadPurchaseLine::query()->where('sanad_purchase_uuid', self::PU1)->orderBy('sanad_uuid')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('1287.5000', $lines[0]->unit_cost);
        $this->assertSame('50.00', $lines[0]->base_quantity);
        $this->assertSame('2026-11-20', $lines[0]->purchase_date->toDateString());
        $this->assertSame(3, SanadRef::query()->count());
        $this->assertSame(['purchase' => 'cursor-1'], $this->device->fresh()->push_cursor);
    }

    public function test_an_older_update_is_ignored_and_reported_as_a_conflict(): void
    {
        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1, 'name' => 'New']), '2026-11-21T10:00:00Z')])->assertOk();

        $response = $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1, 'name' => 'Old']), '2026-11-21T09:30:00Z')]);

        $response->assertOk()->assertJson(['accepted' => 0])->assertJsonPath('conflicts.0.reason', 'formula_lab_newer')
            ->assertJsonPath('conflicts.0.entity', 'product')
            ->assertJsonPath('conflicts.0.local_updated_at', '2026-11-21T10:00:00Z')
            ->assertJsonPath('conflicts.0.central_updated_at', '2026-11-21T09:30:00Z')
            ->assertJsonPath('conflicts.0.central_payload.name', 'Old');
        $this->assertSame('New', SanadProduct::query()->findOrFail(self::P1)->name);
    }

    public function test_an_identical_resend_is_accepted_as_a_no_op_and_a_newer_one_wins(): void
    {
        $item = $this->item('product', self::P1, SanadFake::product(['uuid' => self::P1]));
        $this->push([$item])->assertOk();
        $this->push([$item])->assertOk()->assertJson(['accepted' => 1, 'conflicts' => []]);

        $newer = $this->item('product', self::P1, SanadFake::product(['uuid' => self::P1, 'name' => 'Renamed']), '2026-11-21T11:00:00Z');
        $this->push([$newer])->assertOk()->assertJson(['accepted' => 1]);
        $this->assertSame('Renamed', SanadProduct::query()->findOrFail(self::P1)->name);
    }

    public function test_tombstones_retire_the_cache_and_a_newer_upsert_resurrects_it(): void
    {
        $lineUuid = 'e0000000-0000-4000-8000-000000000001';
        $this->push([
            $this->item('product', self::P1, SanadFake::product(['uuid' => self::P1])),
            $this->item('supplier', self::S1, SanadFake::supplier(['uuid' => self::S1])),
            $this->item('purchase', self::PU1, SanadFake::purchase(['uuid' => self::PU1], [SanadFake::purchaseItem(['uuid' => $lineUuid])])),
        ])->assertOk();

        $this->push([
            $this->item('product', self::P1, ['deleted_at' => '2026-11-21T12:00:00Z'], '2026-11-21T12:00:00Z', 'delete'),
            $this->item('supplier', self::S1, ['deleted_at' => '2026-11-21T12:00:00Z'], '2026-11-21T12:00:00Z', 'delete'),
            $this->item('purchase', self::PU1, ['deleted_at' => '2026-11-21T12:00:00Z'], '2026-11-21T12:00:00Z', 'delete'),
        ])->assertOk()->assertJson(['accepted' => 3]);

        $this->assertTrue(SanadProduct::query()->findOrFail(self::P1)->deleted);
        $this->assertTrue(SanadSupplier::query()->findOrFail(self::S1)->deleted);
        $this->assertSame(0, SanadPurchaseLine::query()->count());
        $this->assertNotNull(SanadRef::query()->where('sanad_uuid', self::PU1)->value('deleted_at'));

        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1]), '2026-11-21T09:45:00Z')])
            ->assertJsonPath('conflicts.0.reason', 'formula_lab_newer');

        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1]), '2026-11-21T13:00:00Z')])->assertJson(['accepted' => 1]);
        $this->assertFalse(SanadProduct::query()->findOrFail(self::P1)->deleted);
        $this->assertNull(SanadRef::query()->where('sanad_uuid', self::P1)->value('deleted_at'));
    }

    public function test_a_purchase_update_drops_lines_that_are_no_longer_in_it(): void
    {
        $keep = 'e0000000-0000-4000-8000-000000000001';
        $drop = 'e0000000-0000-4000-8000-000000000002';
        $this->push([$this->item('purchase', self::PU1, SanadFake::purchase(['uuid' => self::PU1], [SanadFake::purchaseItem(['uuid' => $keep]), SanadFake::purchaseItem(['uuid' => $drop])]))])->assertOk();

        $this->push([$this->item('purchase', self::PU1, SanadFake::purchase(['uuid' => self::PU1], [SanadFake::purchaseItem(['uuid' => $keep])]), '2026-11-21T10:00:00Z')])->assertOk();

        $this->assertSame([$keep], SanadPurchaseLine::query()->pluck('sanad_uuid')->all());
    }

    public function test_malformed_payloads_are_conflicts_not_errors_and_float_decimals_are_rejected(): void
    {
        $response = $this->push([
            $this->item('product', self::P1, ['name' => 'No kind']),
            $this->item('product', 'e0000000-0000-4000-8000-0000000000ff', SanadFake::product(['uuid' => 'e0000000-0000-4000-8000-0000000000ff', 'cost_price' => 41.2])),
            $this->item('supplier', 'not-a-uuid', SanadFake::supplier()),
            $this->item('purchase', self::PU1, SanadFake::purchase(['uuid' => '99999999-9999-4999-8999-999999999999'])),
        ]);

        $response->assertOk()->assertJson(['accepted' => 0]);
        $this->assertSame(['schema_mismatch', 'schema_mismatch', 'invalid_reference', 'invalid_reference'], array_column($response->json('conflicts'), 'reason'));
        $this->assertSame(0, SanadProduct::query()->count());
    }

    public function test_merchandise_is_accepted_but_not_cached_and_batches_are_refs_only(): void
    {
        $merch = SanadFake::product(['uuid' => self::P1, 'item_kind' => 'merchandise']);
        $batch = ['uuid' => 'b0000000-0000-4000-8000-000000000001', 'batch_number' => 'L-1', 'product_uuid' => self::P1, 'cost_price' => '41.20', 'initial_quantity' => '50.000000', 'status' => 'active', 'updated_at' => '2026-11-21T09:30:00Z'];

        $this->push([
            $this->item('product', self::P1, $merch),
            $this->item('batch', $batch['uuid'], $batch),
            $this->item('stock_movement', 'c0000000-0000-4000-8000-000000000001', ['type' => 'production_consume', 'quantity' => '-62.50']),
        ])->assertOk()->assertJson(['accepted' => 3, 'conflicts' => []]);

        $this->assertSame(0, SanadProduct::query()->count());
        $this->assertSame(SanadRefType::Batch, SanadRef::query()->firstOrFail()->entity_type);
    }

    public function test_an_unknown_entity_is_skipped_not_an_error(): void
    {
        $this->push([
            $this->item('warehouse', self::P1, ['x' => 1]),
            $this->item('supplier', self::S1, SanadFake::supplier(['uuid' => self::S1])),
        ])->assertOk()->assertJson(['accepted' => 1, 'skipped' => [['entity' => 'warehouse', 'uuid' => self::P1, 'reason' => 'unknown_entity']]]);
    }

    public function test_request_level_errors_are_422_and_apply_nothing(): void
    {
        $this->push(array_fill(0, 201, $this->item('supplier', self::S1, SanadFake::supplier())))->assertStatus(422);
        $this->push([['entity' => 'supplier', 'uuid' => self::S1, 'op' => 'merge', 'updated_at' => 'x', 'payload' => []]])->assertStatus(422);
        $this->postJson('/api/sanad/v1/changes', [], $this->headers('k-empty'))->assertStatus(422);
        $this->assertSame(0, SanadSupplier::query()->count());
    }

    public function test_the_batch_is_atomic_when_the_database_rejects_an_item(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->push([
                $this->item('supplier', self::S1, SanadFake::supplier(['uuid' => self::S1])),
                $this->item('purchase', self::PU1, SanadFake::purchase(['uuid' => self::PU1, 'currency' => 'TOO-LONG-FOR-CHAR3'])),
            ], 'atomic');
            $this->fail('expected exception');
        } catch (QueryException) {
            $this->assertSame(0, SanadSupplier::query()->count());
            $this->assertSame(0, SanadRef::query()->count());
            $this->assertDatabaseMissing('idempotency_keys', ['key' => 'atomic']);
        }
    }

    public function test_the_device_cursor_is_kept_per_entity_and_empty_batches_are_fine(): void
    {
        $this->push([$this->item('supplier', self::S1, SanadFake::supplier(['uuid' => self::S1]))], cursor: 'sup-cursor')->assertOk();
        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1]))], cursor: 'prod-cursor')->assertOk();
        $this->push([], cursor: null)->assertOk()->assertJson(['accepted' => 0, 'cursor' => null]);

        $this->assertEquals(['supplier' => 'sup-cursor', 'product' => 'prod-cursor'], $this->device->fresh()->push_cursor);
    }

    public function test_one_tenant_cannot_overwrite_another_tenants_cache(): void
    {
        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1, 'name' => 'Mine']))])->assertOk();
        $other = SanadDevice::factory()->for(Tenant::factory()->create())->withToken('other-tenant')->create();

        $response = $this->postJson('/api/sanad/v1/changes', ['items' => [$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1, 'name' => 'Theirs']), '2026-11-21T15:00:00Z')]], $this->headers('x1', 'other-tenant'));

        $response->assertOk()->assertJsonPath('conflicts.0.reason', 'invalid_reference');
        $this->assertSame('Mine', SanadProduct::query()->findOrFail(self::P1)->name);
        $this->assertNotNull($other);
    }

    public function test_a_pushed_product_refreshes_the_ref_of_an_already_linked_material_and_a_tombstone_unlinks_it(): void
    {
        $material = TradeMaterial::factory()->create(['tenant_id' => $this->tenant->id, 'sanad_product_uuid' => self::P1]);

        $this->push([$this->item('product', self::P1, SanadFake::product(['uuid' => self::P1]))])->assertOk();
        $ref = SanadRef::query()->where('sanad_uuid', self::P1)->firstOrFail();
        $this->assertSame($material->id, $ref->local_id);
        $this->assertSame('trade_materials', $ref->local_table);

        $this->push([$this->item('product', self::P1, ['deleted_at' => '2026-11-21T12:00:00Z'], '2026-11-21T12:00:00Z', 'delete')])->assertOk();
        $this->assertNull($material->fresh()->sanad_product_uuid);
        $this->assertNull($ref->fresh()->local_id);
    }
}
