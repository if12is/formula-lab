<?php

use App\Models\CalcRun;
use App\Models\DatasetVersion;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Tenant;
use App\Services\Reference\CalcRunRecorder;
use App\Services\Reference\DatasetSnapshotService;
use App\Support\CanonicalJson;
use App\Support\DecimalNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Runs the TS engine's canonicalJson + sha256Hex over a JSON file (Node strips the types natively).
 *
 * @return array{canonical: string, sha256: string}
 */
function engineCanonical(string $jsonPath): array
{
    $process = new Process(
        ['node', '--experimental-strip-types', '--no-warnings', __DIR__.'/../../fixtures/engine-canonical.mjs', $jsonPath],
    );
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function nodeAvailable(): bool
{
    return (new Process(['node', '--version']))->run() === 0;
}

function seedReferenceData(): void
{
    $source = Source::factory()->create(['title' => 'Source A']);
    PropertyValue::factory()->count(3)->create(['source_id' => $source->id]);
    Price::factory()->create(['source_id' => $source->id]);
}

beforeEach(fn () => $this->service = app(DatasetSnapshotService::class));

describe('canonical JSON parity with the TS engine', function () {
    it('matches the engine byte for byte on the shared fixture', function () {
        $path = __DIR__.'/../../fixtures/canonical-json-cases.json';
        $cases = json_decode(file_get_contents($path), false, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $name => $case) {
            $file = tempnam(sys_get_temp_dir(), 'cj');
            file_put_contents($file, json_encode($case));
            $engine = engineCanonical($file);
            unlink($file);

            expect(CanonicalJson::encode($case))->toBe($engine['canonical'], $name)
                ->and(CanonicalJson::hash($case))->toBe($engine['sha256'], $name);
        }
    })->skip(fn () => ! nodeAvailable(), 'node is not installed');

    it('hashes a real snapshot to the same value as the engine', function () {
        seedReferenceData();
        $snapshot = $this->service->snapshot();

        $file = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($file, json_encode($snapshot));
        $engine = engineCanonical($file);
        unlink($file);

        expect($this->service->hash($snapshot))->toBe($engine['sha256']);
    })->skip(fn () => ! nodeAvailable(), 'node is not installed');

    it('sorts keys recursively without whitespace', function () {
        expect(CanonicalJson::encode(['b' => ['z' => '1', 'a' => null], 'a' => [true, 'x']]))
            ->toBe('{"a":[true,"x"],"b":{"a":null,"z":"1"}}');
    });

    it('forbids numbers and unsupported types', function () {
        expect(fn () => CanonicalJson::encode(['a' => 1]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => CanonicalJson::encode(1.5))->toThrow(InvalidArgumentException::class)
            ->and(fn () => CanonicalJson::encode(new DateTime))->toThrow(InvalidArgumentException::class);
    });

    it('normalizes decimal strings', function (string $in, string $out) {
        expect(DecimalNormalizer::normalize($in))->toBe($out);
    })->with([['1.50', '1.5'], ['007', '7'], ['0.000', '0'], ['-0.0', '0'], ['-2.50', '-2.5'], ['10', '10']]);

    it('rejects non-decimal text', function (string $in) {
        expect(fn () => DecimalNormalizer::normalize($in))->toThrow(InvalidArgumentException::class);
    })->with(['1e3', '1,5', '', '.5', 'abc']);
});

describe('snapshot and dataset versions', function () {
    it('is stable across runs', function () {
        seedReferenceData();

        expect($this->service->hash($this->service->snapshot()))
            ->toBe($this->service->hash($this->service->snapshot()));
    });

    it('does not depend on row insertion order or timestamps', function () {
        $source = Source::factory()->create(['id' => '00000000-0000-7000-8000-000000000001']);
        $rows = collect(['a', 'b', 'c'])->map(fn ($p, $i) => [
            'id' => "00000000-0000-7000-8000-00000000010{$i}", 'property' => $p,
        ]);

        $rows->each(fn ($r) => PropertyValue::factory()->create($r + ['source_id' => $source->id, 'entity_id' => '00000000-0000-7000-8000-000000000999']));
        $first = $this->service->hash($this->service->snapshot());

        PropertyValue::query()->delete();
        $this->travel(2)->days();
        $rows->reverse()->each(fn ($r) => PropertyValue::factory()->create($r + ['source_id' => $source->id, 'entity_id' => '00000000-0000-7000-8000-000000000999']));

        expect($this->service->hash($this->service->snapshot()))->toBe($first);
    });

    it('does not depend on decimal formatting', function () {
        $source = Source::factory()->create();
        $pv = PropertyValue::factory()->create(['source_id' => $source->id, 'value' => '1.5']);
        $first = $this->service->hash($this->service->snapshot());

        $pv->update(['value' => '1.500']);

        expect($this->service->hash($this->service->snapshot()))->toBe($first);
    });

    it('changes when any value changes', function () {
        $pv = PropertyValue::factory()->create(['value' => '1.5']);
        $first = $this->service->hash($this->service->snapshot());

        $pv->update(['value' => '1.6']);

        expect($this->service->hash($this->service->snapshot()))->not->toBe($first);
    });

    it('scopes tenant rows: global snapshot ignores them, tenant snapshot includes them', function () {
        $tenant = Tenant::factory()->create();
        $source = Source::factory()->create();
        PropertyValue::factory()->create(['tenant_id' => null, 'source_id' => $source->id]);
        $global = $this->service->snapshot();
        PropertyValue::factory()->create(['tenant_id' => $tenant->id, 'source_id' => $source->id]);

        expect($this->service->snapshot()['property_values'])->toHaveCount(1)
            ->and($this->service->snapshot($tenant->id)['property_values'])->toHaveCount(2)
            ->and($this->service->hash($this->service->snapshot()))->toBe($this->service->hash($global));
    });

    it('emits decimals as strings and never as numbers', function () {
        seedReferenceData();
        $snapshot = $this->service->snapshot();

        expect($snapshot['prices'][0]['amount'])->toBeString()
            ->and($snapshot['property_values'][0]['value'])->toBeString();
    });

    it('publishes a version identified by the hash and is idempotent', function () {
        seedReferenceData();

        $version = $this->service->publish(notes: 'first');
        $again = $this->service->publish(notes: 'ignored');

        expect($version->id)->toBe($this->service->hash($this->service->snapshot()))
            ->and($again->id)->toBe($version->id)
            ->and(DatasetVersion::count())->toBe(1)
            ->and($version->fresh()->manifest['counts'])->toEqual(['sources' => 1, 'property_values' => 3, 'prices' => 1]);
    });

    it('publishes a new version when data changes and keeps the old one', function () {
        seedReferenceData();
        $old = $this->service->publish();
        PropertyValue::first()->update(['value' => '9.99']);

        $new = $this->service->publish();

        expect($new->id)->not->toBe($old->id)
            ->and(DatasetVersion::count())->toBe(2);
    });

    it('refuses to update or delete a published dataset version', function () {
        $version = DatasetVersion::factory()->create();

        expect(fn () => DB::transaction(fn () => DB::table('dataset_versions')->where('id', $version->id)->update(['notes' => 'x'])))
            ->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('dataset_versions')->where('id', $version->id)->delete()))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses an id that is not a sha256', function () {
        expect(fn () => DB::transaction(fn () => DatasetVersion::factory()->create(['id' => 'v1'])))
            ->toThrow(QueryException::class);
    });
});

describe('calc runs', function () {
    it('records the dataset version, hashes and engine version', function () {
        seedReferenceData();
        $dataset = $this->service->publish();

        $run = app(CalcRunRecorder::class)->record(str_repeat('a', 64), str_repeat('b', 64), '0.1.0', $dataset->id);

        $fresh = $run->fresh();
        expect($fresh->dataset_version)->toBe($dataset->id)
            ->and($fresh->engine_version)->toBe('0.1.0')
            ->and($run->datasetVersion->is($dataset))->toBeTrue()
            ->and(CalcRun::count())->toBe(1);
    });

    it('refuses a run that points at an unknown dataset version', function () {
        expect(fn () => DB::transaction(fn () => app(CalcRunRecorder::class)->record(
            str_repeat('a', 64), str_repeat('b', 64), '0.1.0', str_repeat('c', 64),
        )))->toThrow(QueryException::class);
    });
});
