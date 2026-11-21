<?php

namespace Tests\Feature\Data;

use App\Models\CalcRun;
use App\Services\Reference\CalcRunService;
use App\Services\Reference\DatasetSnapshotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class CalcRunTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    public function test_it_records_the_dataset_version_hashes_and_engine_version(): void
    {
        $this->seedReferenceData();
        $dataset = app(DatasetSnapshotService::class)->publish();

        $run = app(CalcRunService::class)->record(str_repeat('a', 64), str_repeat('b', 64), '0.1.0', $dataset->id);

        $fresh = $run->fresh();
        $this->assertSame($dataset->id, $fresh->dataset_version);
        $this->assertSame('0.1.0', $fresh->engine_version);
        $this->assertTrue($run->datasetVersion->is($dataset));
        $this->assertSame(1, CalcRun::count());
    }

    public function test_it_refuses_a_run_that_points_at_an_unknown_dataset_version(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => app(CalcRunService::class)->record(
            str_repeat('a', 64), str_repeat('b', 64), '0.1.0', str_repeat('c', 64),
        )));
    }
}
