<?php

namespace Tests\Feature\Data;

use App\Models\DatasetVersion;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Tenant;
use App\Services\Reference\DatasetSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class DatasetVersionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private DatasetSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DatasetSnapshotService::class);
    }

    public function test_it_is_stable_across_runs(): void
    {
        $this->seedReferenceData();

        $this->assertSame(
            $this->service->hash($this->service->snapshot()),
            $this->service->hash($this->service->snapshot()),
        );
    }

    public function test_it_does_not_depend_on_row_insertion_order_or_timestamps(): void
    {
        $source = Source::factory()->create(['id' => '00000000-0000-7000-8000-000000000001']);
        $rows = collect(['a', 'b', 'c'])->map(fn ($p, $i) => [
            'id' => "00000000-0000-7000-8000-00000000010{$i}", 'property' => $p,
        ]);

        $rows->each(fn ($r) => PropertyValue::factory()->create($r + ['source_id' => $source->id, 'entity_id' => '00000000-0000-7000-8000-000000000999']));
        $first = $this->service->hash($this->service->snapshot());

        PropertyValue::query()->delete();
        $this->travel(2)->days();
        $rows->reverse()->each(fn ($r) => PropertyValue::factory()->create($r + ['source_id' => $source->id, 'entity_id' => '00000000-0000-7000-8000-000000000999']));

        $this->assertSame($first, $this->service->hash($this->service->snapshot()));
    }

    public function test_it_does_not_depend_on_decimal_formatting(): void
    {
        $source = Source::factory()->create();
        $pv = PropertyValue::factory()->create(['source_id' => $source->id, 'value' => '1.5']);
        $first = $this->service->hash($this->service->snapshot());

        $pv->update(['value' => '1.500']);

        $this->assertSame($first, $this->service->hash($this->service->snapshot()));
    }

    public function test_it_changes_when_any_value_changes(): void
    {
        $pv = PropertyValue::factory()->create(['value' => '1.5']);
        $first = $this->service->hash($this->service->snapshot());

        $pv->update(['value' => '1.6']);

        $this->assertNotSame($first, $this->service->hash($this->service->snapshot()));
    }

    public function test_it_scopes_tenant_rows_global_snapshot_ignores_them_tenant_snapshot_includes_them(): void
    {
        $tenant = Tenant::factory()->create();
        $source = Source::factory()->create();
        PropertyValue::factory()->create(['tenant_id' => null, 'source_id' => $source->id]);
        $global = $this->service->snapshot();
        PropertyValue::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

        $this->assertCount(1, $this->service->snapshot()['property_values']);
        $this->assertCount(2, $this->service->snapshot($tenant->id)['property_values']);
        $this->assertSame($this->service->hash($global), $this->service->hash($this->service->snapshot()));
    }

    public function test_it_emits_decimals_as_strings_and_never_as_numbers(): void
    {
        $this->seedReferenceData();
        $snapshot = $this->service->snapshot();

        $this->assertIsString($snapshot['prices'][0]['amount']);
        $this->assertIsString($snapshot['property_values'][0]['value']);
    }

    public function test_it_publishes_a_version_identified_by_the_hash_and_is_idempotent(): void
    {
        $this->seedReferenceData();

        $version = $this->service->publish(notes: 'first');
        $again = $this->service->publish(notes: 'ignored');

        $this->assertSame($this->service->hash($this->service->snapshot()), $version->id);
        $this->assertSame($version->id, $again->id);
        $this->assertSame(1, DatasetVersion::count());
        $this->assertEquals(['sources' => 1, 'property_values' => 3, 'prices' => 1], $version->fresh()->manifest['counts']);
    }

    public function test_it_publishes_a_new_version_when_data_changes_and_keeps_the_old_one(): void
    {
        $this->seedReferenceData();
        $old = $this->service->publish();
        PropertyValue::first()->update(['value' => '9.99']);

        $new = $this->service->publish();

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(2, DatasetVersion::count());
    }

    public function test_it_refuses_to_update_or_delete_a_published_dataset_version(): void
    {
        $version = DatasetVersion::factory()->create();

        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('dataset_versions')->where('id', $version->id)->update(['notes' => 'x'])), 'append-only');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('dataset_versions')->where('id', $version->id)->delete()), 'append-only');
    }

    public function test_it_refuses_an_id_that_is_not_a_sha256(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DatasetVersion::factory()->create(['id' => 'v1'])));
    }
}
