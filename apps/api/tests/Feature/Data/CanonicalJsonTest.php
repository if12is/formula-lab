<?php

namespace Tests\Feature\Data;

use App\Services\Reference\DatasetSnapshotService;
use App\Support\CanonicalJson;
use App\Support\DecimalNormalizer;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

class CanonicalJsonTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    /**
     * Runs the TS engine's canonicalJson + sha256Hex over a JSON file (Node strips the types natively).
     *
     * @return array{canonical: string, sha256: string}
     */
    private function engineCanonical(string $jsonPath): array
    {
        $process = new Process(
            ['node', '--experimental-strip-types', '--no-warnings', __DIR__.'/../../fixtures/engine-canonical.mjs', $jsonPath],
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function skipWithoutNode(): void
    {
        if ((new Process(['node', '--version']))->run() !== 0) {
            $this->markTestSkipped('node is not installed');
        }
    }

    public function test_it_matches_the_engine_byte_for_byte_on_the_shared_fixture(): void
    {
        $this->skipWithoutNode();

        $path = __DIR__.'/../../fixtures/canonical-json-cases.json';
        $cases = json_decode(file_get_contents($path), false, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $name => $case) {
            $file = tempnam(sys_get_temp_dir(), 'cj');
            file_put_contents($file, json_encode($case));
            $engine = $this->engineCanonical($file);
            unlink($file);

            $this->assertSame($engine['canonical'], CanonicalJson::encode($case), $name);
            $this->assertSame($engine['sha256'], CanonicalJson::hash($case), $name);
        }
    }

    public function test_it_hashes_a_real_snapshot_to_the_same_value_as_the_engine(): void
    {
        $this->skipWithoutNode();

        $this->seedReferenceData();
        $service = app(DatasetSnapshotService::class);
        $snapshot = $service->snapshot();

        $file = tempnam(sys_get_temp_dir(), 'snap');
        file_put_contents($file, json_encode($snapshot));
        $engine = $this->engineCanonical($file);
        unlink($file);

        $this->assertSame($engine['sha256'], $service->hash($snapshot));
    }

    public function test_it_sorts_keys_recursively_without_whitespace(): void
    {
        $this->assertSame(
            '{"a":[true,"x"],"b":{"a":null,"z":"1"}}',
            CanonicalJson::encode(['b' => ['z' => '1', 'a' => null], 'a' => [true, 'x']]),
        );
    }

    public function test_it_forbids_numbers_and_unsupported_types(): void
    {
        $this->assertThrowsException(InvalidArgumentException::class, fn () => CanonicalJson::encode(['a' => 1]));
        $this->assertThrowsException(InvalidArgumentException::class, fn () => CanonicalJson::encode(1.5));
        $this->assertThrowsException(InvalidArgumentException::class, fn () => CanonicalJson::encode(new DateTime));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function decimalStrings(): array
    {
        return [
            '1.50' => ['1.50', '1.5'],
            '007' => ['007', '7'],
            '0.000' => ['0.000', '0'],
            '-0.0' => ['-0.0', '0'],
            '-2.50' => ['-2.50', '-2.5'],
            '10' => ['10', '10'],
        ];
    }

    #[DataProvider('decimalStrings')]
    public function test_it_normalizes_decimal_strings(string $in, string $out): void
    {
        $this->assertSame($out, DecimalNormalizer::normalize($in));
    }

    /** @return array<string, array{0: string}> */
    public static function nonDecimalStrings(): array
    {
        return [
            'exponent' => ['1e3'],
            'comma' => ['1,5'],
            'empty' => [''],
            'no leading digit' => ['.5'],
            'letters' => ['abc'],
        ];
    }

    #[DataProvider('nonDecimalStrings')]
    public function test_it_rejects_non_decimal_text(string $in): void
    {
        $this->assertThrowsException(InvalidArgumentException::class, fn () => DecimalNormalizer::normalize($in));
    }
}
