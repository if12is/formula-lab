<?php

namespace Tests\Feature\Sanad;

use App\Models\SanadCustomer;
use App\Models\SanadRef;
use App\Services\Sanad\SanadCustomerSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\fakes\sanad\SanadContract;
use Tests\fakes\sanad\SanadFake;

/**
 * The customers feed (contract 8.5 / 8.6): pushed as the `customer` entity or read over LAN, both end in the same read-only
 * cache (uuid, code, names, is_active, is_wholesale, price_list_uuid and nothing else) and a `customer` ref.
 */
class SanadCustomerSyncTest extends SanadTestCase
{
    private const CUSTOMER = 'c1000000-0000-4000-8000-000000000001';

    private const LIST = 'a1000000-0000-4000-8000-000000000001';

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => self::CUSTOMER, 'code' => 'CLT-0001', 'name' => 'تاجر الجملة', 'name_en' => 'Wholesaler',
            'is_active' => true, 'is_wholesale' => true, 'price_list_uuid' => self::LIST, 'updated_at' => '2026-11-21T09:30:00Z',
        ];
    }

    public function test_a_pushed_customer_is_cached_with_only_the_pricing_fields_and_a_ref(): void
    {
        $this->push([$this->item('customer', self::CUSTOMER, $this->payload() + ['balance' => '900.00', 'phone' => '0100', 'address' => 'x'])])
            ->assertOk()->assertJson(['accepted' => 1, 'conflicts' => [], 'skipped' => []]);

        $customer = SanadCustomer::query()->findOrFail(self::CUSTOMER);
        $this->assertSame($this->tenant->id, $customer->tenant_id);
        $this->assertSame('CLT-0001', $customer->code);
        $this->assertSame('تاجر الجملة', $customer->name);
        $this->assertSame('Wholesaler', $customer->name_en);
        $this->assertTrue($customer->is_active);
        $this->assertTrue($customer->is_wholesale);
        $this->assertSame(self::LIST, $customer->price_list_uuid);
        $this->assertSame(['uuid', 'tenant_id', 'code', 'name', 'name_en', 'is_active', 'is_wholesale', 'price_list_uuid', 'updated_at', 'deleted', 'synced_at'], array_keys($customer->getAttributes()), 'balance, phone and address are never stored');
        $this->assertSame(1, SanadRef::query()->where('entity_type', 'customer')->where('sanad_uuid', self::CUSTOMER)->count());
    }

    public function test_a_customer_that_loses_its_list_arrives_as_a_normal_upsert(): void
    {
        $this->push([$this->item('customer', self::CUSTOMER, $this->payload())]);
        $this->push([$this->item('customer', self::CUSTOMER, $this->payload(['price_list_uuid' => null, 'updated_at' => '2026-11-21T10:00:00Z']), '2026-11-21T10:00:00Z')]);

        $this->assertNull(SanadCustomer::query()->findOrFail(self::CUSTOMER)->price_list_uuid);
    }

    public function test_an_archived_customer_is_a_tombstone_kept_in_the_cache_marked_deleted(): void
    {
        $this->push([$this->item('customer', self::CUSTOMER, $this->payload())]);
        $this->push([$this->item('customer', self::CUSTOMER, ['deleted_at' => '2026-11-21T11:00:00Z'], '2026-11-21T11:00:00Z', 'delete')])->assertOk()->assertJson(['accepted' => 1]);

        $this->assertTrue(SanadCustomer::query()->findOrFail(self::CUSTOMER)->deleted);
        $this->assertNotNull(SanadRef::query()->where('entity_type', 'customer')->firstOrFail()->deleted_at);
    }

    public function test_an_older_update_a_bad_shape_and_a_foreign_uuid_are_conflicts_not_errors(): void
    {
        $this->push([$this->item('customer', self::CUSTOMER, $this->payload())]);

        $stale = $this->push([$this->item('customer', self::CUSTOMER, $this->payload(['name' => 'Old']), '2026-11-21T08:00:00Z')])->assertOk();
        $this->assertSame('formula_lab_newer', $stale->json('conflicts.0.reason'));
        $this->assertSame('تاجر الجملة', SanadCustomer::query()->findOrFail(self::CUSTOMER)->name);

        $bad = $this->push([$this->item('customer', 'c2000000-0000-4000-8000-000000000002', ['name' => '', 'price_list_uuid' => 'not-a-uuid'])])->assertOk();
        $this->assertSame('schema_mismatch', $bad->json('conflicts.0.reason'));
        $this->assertSame(0, SanadCustomer::query()->where('uuid', 'c2000000-0000-4000-8000-000000000002')->count());

        $other = $this->push([$this->item('customer', 'c3000000-0000-4000-8000-000000000003', $this->payload(['uuid' => 'c4000000-0000-4000-8000-000000000004']))])->assertOk();
        $this->assertSame('invalid_reference', $other->json('conflicts.0.reason'));
    }

    public function test_a_resend_is_a_no_op_and_the_customer_cursor_is_kept_per_entity(): void
    {
        $item = $this->item('customer', self::CUSTOMER, $this->payload());
        $this->push([$item], cursor: 'customer-cursor-1');
        $this->push([$item], cursor: 'customer-cursor-1')->assertJson(['accepted' => 1]);
        $this->push([$this->item('supplier', 'd1000000-0000-4000-8000-000000000001', ['name' => 'S'])], cursor: 'supplier-cursor-1');

        $this->assertSame(1, SanadCustomer::query()->count());
        $this->assertSame(['customer' => 'customer-cursor-1', 'supplier' => 'supplier-cursor-1'], $this->device->fresh()->push_cursor);
    }

    public function test_one_tenant_cannot_overwrite_another_tenants_customer(): void
    {
        SanadCustomer::factory()->create(['uuid' => self::CUSTOMER, 'name' => 'Theirs']);

        $response = $this->push([$this->item('customer', self::CUSTOMER, $this->payload())])->assertOk();

        $this->assertSame('invalid_reference', $response->json('conflicts.0.reason'));
        $this->assertSame('Theirs', SanadCustomer::query()->findOrFail(self::CUSTOMER)->name);
    }

    public function test_lan_pull_follows_cursors_applies_tombstones_and_is_idempotent(): void
    {
        Sleep::fake();
        SanadFake::use();
        $page1 = [SanadFake::customer(['uuid' => 'c5000000-0000-4000-8000-000000000001']), SanadFake::customer(['uuid' => 'c5000000-0000-4000-8000-000000000002', 'price_list_uuid' => self::LIST])];
        $page2 = [SanadFake::tombstone('c5000000-0000-4000-8000-000000000003')];
        SanadFake::customerPages([$page1, $page2]);
        $sync = app(SanadCustomerSyncService::class);

        $first = $sync->pull($this->tenant->id);
        $again = $sync->pull($this->tenant->id);

        $this->assertSame(0, $first->conflicts);
        $this->assertSame(2, $first->pages);
        $this->assertFalse($first->hasMore);
        $this->assertSame(2, SanadCustomer::query()->where('deleted', false)->count());
        $this->assertSame(self::LIST, SanadCustomer::query()->findOrFail('c5000000-0000-4000-8000-000000000002')->price_list_uuid);
        $this->assertSame(0, $again->conflicts);
        $this->assertSame(2, SanadCustomer::query()->where('deleted', false)->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/manufacturing/customers') && $request->method() === 'GET');
    }

    public function test_lan_pull_stops_at_the_page_limit_and_hands_back_the_cursor(): void
    {
        Sleep::fake();
        SanadFake::use();
        config(['sanad.client.max_pages_per_pull' => 1]);
        SanadFake::customerPages([[SanadFake::customer()], [SanadFake::customer()]]);

        $result = app(SanadCustomerSyncService::class)->pull($this->tenant->id);

        $this->assertTrue($result->hasMore);
        $this->assertSame('page-2', $result->cursors['customer']);
    }

    public function test_the_fake_and_the_fixture_customers_follow_the_contract(): void
    {
        SanadContract::assertCustomer(SanadFake::customer());
        foreach (SanadFake::fixture('customers') as $row) {
            if (($row['deleted'] ?? false) === true) {
                SanadContract::assertTombstone($row);
            } else {
                SanadContract::assertCustomer($row);
            }
        }
    }
}
