<?php

namespace Tests\Feature\Actions;

use App\Actions\Reference\RecordCalcRunAction;
use App\Models\CalcRun;
use App\Models\DatasetVersion;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordCalcRunActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_hashes_engine_version_and_dataset_version(): void
    {
        $dataset = DatasetVersion::factory()->create();

        $run = app(RecordCalcRunAction::class)(str_repeat('a', 64), str_repeat('b', 64), '0.1.0', $dataset->id);

        $this->assertSame($dataset->id, $run->fresh()->dataset_version);
        $this->assertSame('0.1.0', $run->fresh()->engine_version);
        $this->assertNull($run->fresh()->tenant_id);
    }

    public function test_it_records_the_tenant_when_given(): void
    {
        $dataset = DatasetVersion::factory()->create();
        $tenant = Tenant::factory()->create();

        $run = app(RecordCalcRunAction::class)(str_repeat('a', 64), str_repeat('b', 64), '0.1.0', $dataset->id, $tenant->id);

        $this->assertSame($tenant->id, $run->fresh()->tenant_id);
        $this->assertSame(1, CalcRun::count());
    }

    public function test_it_refuses_an_unknown_dataset_version(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => app(RecordCalcRunAction::class)(
            str_repeat('a', 64), str_repeat('b', 64), '0.1.0', str_repeat('c', 64),
        )));
    }
}
