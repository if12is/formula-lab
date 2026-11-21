<?php

namespace Tests\Feature\Actions;

use App\Actions\Reference\PublishDatasetVersionAction;
use App\Models\DatasetVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublishDatasetVersionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_version_under_its_hash(): void
    {
        $hash = str_repeat('a', 64);

        $version = app(PublishDatasetVersionAction::class)($hash, ['schema' => 'x'], 'first');

        $this->assertSame($hash, $version->id);
        $this->assertSame('first', $version->fresh()->notes);
        $this->assertSame(['schema' => 'x'], $version->fresh()->manifest);
    }

    public function test_it_returns_the_existing_version_for_a_published_hash(): void
    {
        $hash = str_repeat('b', 64);
        $action = app(PublishDatasetVersionAction::class);

        $action($hash, ['n' => 1], 'kept');
        $again = $action($hash, ['n' => 2], 'ignored');

        $this->assertSame('kept', $again->notes);
        $this->assertSame(1, DatasetVersion::count());
    }

    public function test_it_refuses_an_id_that_is_not_a_sha256(): void
    {
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => app(PublishDatasetVersionAction::class)('v1', [])));
    }
}
