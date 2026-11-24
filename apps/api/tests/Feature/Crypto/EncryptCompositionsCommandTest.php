<?php

namespace Tests\Feature\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Crypto\CompositionCipher;
use App\Services\Crypto\TenantKeyring;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** U-02: rows saved before encryption are sealed in place, verified by the version hash before and after. */
class EncryptCompositionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    private TradeMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formula = Formula::factory()->create();
        $this->material = TradeMaterial::factory()->create();
    }

    /** A version as it was stored before U-02: plaintext columns, hash computed on the plaintext. */
    private function legacyVersion(string $status = 'draft'): string
    {
        $versionId = fake()->uuid();
        $qs = TradeMaterial::factory()->create();
        $hash = FormulaVersion::hashLines([
            ['lineNo' => '1', 'tradeMaterialId' => $this->material->id, 'percentWw' => '12.5', 'isQs' => false, 'phase' => 'A', 'additionTempC' => '70'],
            ['lineNo' => '2', 'tradeMaterialId' => $qs->id, 'percentWw' => null, 'isQs' => true, 'phase' => null],
        ]);
        DB::table('formula_versions')->insert(['id' => $versionId, 'formula_id' => $this->formula->id, 'version' => 1, 'hash' => $hash, 'status' => 'draft']);
        DB::table('formula_lines')->insert(['id' => fake()->uuid(), 'formula_version_id' => $versionId, 'line_no' => 1, 'trade_material_id' => $this->material->id, 'percent_ww' => '12.5', 'is_qs' => false, 'phase' => 'A', 'addition_temp_c' => '70']);
        DB::table('formula_lines')->insert(['id' => fake()->uuid(), 'formula_version_id' => $versionId, 'line_no' => 2, 'trade_material_id' => $qs->id, 'percent_ww' => null, 'is_qs' => true]);
        DB::table('formula_versions')->where('id', $versionId)->update(['status' => $status]);

        return $versionId;
    }

    private function rawLines(string $versionId): string
    {
        return json_encode(DB::table('formula_lines')->where('formula_version_id', $versionId)->get());
    }

    public function test_dry_run_verifies_the_hash_and_changes_nothing(): void
    {
        $versionId = $this->legacyVersion();
        $before = $this->rawLines($versionId);

        $this->artisan('crypto:encrypt-compositions', ['--dry-run' => true])
            ->expectsOutputToContain(__('crypto.command.dry_run'))
            ->expectsOutputToContain(__('crypto.command.encrypt_summary', ['scanned' => 1, 'pending' => 1, 'encrypted' => 0, 'columns' => 0, 'already' => 0]))
            ->assertSuccessful();

        $this->assertSame($before, $this->rawLines($versionId));
        $this->assertNull(Tenant::query()->whereKey($this->formula->tenant_id)->value('wrapped_data_key'), 'a dry run does not even create the tenant key');
    }

    public function test_the_real_run_encrypts_every_composition_column_and_the_hash_is_identical_before_and_after(): void
    {
        $versionId = $this->legacyVersion();
        $recorded = DB::table('formula_versions')->where('id', $versionId)->value('hash');
        $this->assertStringContainsString('12.5', $this->rawLines($versionId));

        $this->artisan('crypto:encrypt-compositions')
            ->expectsOutputToContain(__('crypto.command.encrypt_summary', ['scanned' => 1, 'pending' => 1, 'encrypted' => 1, 'columns' => 4, 'already' => 0]))
            ->assertSuccessful();

        $raw = $this->rawLines($versionId);
        $this->assertStringNotContainsString($this->material->id, $raw);
        $this->assertStringNotContainsString('12.5', $raw);
        $version = FormulaVersion::query()->findOrFail($versionId);
        $this->assertSame($recorded, $version->hash, 'the recorded hash is not touched');
        $this->assertSame($recorded, CanonicalJson::hash($version->canonicalContent()), 'and the decrypted content still hashes to it');
        $this->assertTrue($version->verifyHash());
        $this->assertSame('12.5', $version->lines->first()->percent_ww);
    }

    public function test_it_can_encrypt_the_lines_of_an_approved_version_and_the_guard_stays_shut_otherwise(): void
    {
        $versionId = $this->legacyVersion('approved');

        $this->artisan('crypto:encrypt-compositions')->assertSuccessful();

        $this->assertTrue(FormulaVersion::query()->findOrFail($versionId)->verifyHash());
        // The exemption exists only inside the command's transaction and only for the encrypted columns.
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_lines')->where('formula_version_id', $versionId)->where('line_no', 1)->update(['percent_ww' => 'x'])), 'immutable');
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(function () use ($versionId): void {
            DB::statement("select set_config('fl.reencrypt', 'on', true)");
            DB::table('formula_lines')->where('formula_version_id', $versionId)->where('line_no', 1)->update(['phase' => 'changed']);
        }), 'immutable');
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $versionId = $this->legacyVersion();
        $this->artisan('crypto:encrypt-compositions')->assertSuccessful();
        $sealed = $this->rawLines($versionId);

        $this->artisan('crypto:encrypt-compositions')
            ->expectsOutputToContain(__('crypto.command.encrypt_summary', ['scanned' => 1, 'pending' => 0, 'encrypted' => 0, 'columns' => 0, 'already' => 4]))
            ->assertSuccessful();

        $this->assertSame($sealed, $this->rawLines($versionId));
    }

    public function test_a_version_whose_hash_does_not_match_its_plaintext_is_left_untouched_and_reported(): void
    {
        $versionId = $this->legacyVersion();
        DB::table('formula_lines')->where('formula_version_id', $versionId)->where('line_no', 1)->update(['percent_ww' => '13']);
        $before = $this->rawLines($versionId);

        $this->artisan('crypto:encrypt-compositions')
            ->expectsOutputToContain(__('crypto.command.encrypt_failure', ['version' => $versionId, 'reason' => __('crypto.command.encrypt_hash_mismatch')]))
            ->assertFailed();

        $this->assertSame($before, $this->rawLines($versionId));
    }

    public function test_a_tenant_option_limits_the_run(): void
    {
        $mine = $this->legacyVersion();
        $other = Formula::factory()->create();
        $otherVersion = fake()->uuid();
        DB::table('formula_versions')->insert(['id' => $otherVersion, 'formula_id' => $other->id, 'version' => 1, 'hash' => str_repeat('a', 64), 'status' => 'draft']);
        DB::table('formula_lines')->insert(['id' => fake()->uuid(), 'formula_version_id' => $otherVersion, 'line_no' => 1, 'trade_material_id' => $this->material->id, 'percent_ww' => '5', 'is_qs' => false]);

        $this->artisan('crypto:encrypt-compositions', ['--tenant' => $this->formula->tenant_id])->assertSuccessful();

        $this->assertStringNotContainsString('12.5', $this->rawLines($mine));
        $this->assertStringContainsString('"5"', $this->rawLines($otherVersion), 'the other tenant was not visited');
        $this->artisan('crypto:encrypt-compositions', ['--tenant' => fake()->uuid()])->assertFailed();
    }

    public function test_reads_of_unmigrated_rows_are_refused_unless_the_legacy_window_is_open(): void
    {
        $versionId = $this->legacyVersion();

        $this->assertThrowsException(CompositionCryptoException::class, fn () => FormulaVersion::query()->findOrFail($versionId)->lines->first()->percent_ww, __('exceptions.crypto_not_encrypted', ['column' => 'formula_lines.percent_ww']));
        $this->assertFalse(FormulaVersion::query()->findOrFail($versionId)->verifyHash());

        config(['crypto.allow_legacy_plaintext' => true]);
        $this->assertSame('12.5', FormulaVersion::query()->findOrFail($versionId)->lines->first()->percent_ww);
        $this->assertTrue(FormulaVersion::query()->findOrFail($versionId)->verifyHash());
    }

    public function test_without_a_master_key_the_run_fails_and_writes_nothing(): void
    {
        $versionId = $this->legacyVersion();
        $before = $this->rawLines($versionId);
        config(['crypto.master_key' => null]);
        app(TenantKeyring::class)->flush();

        $this->artisan('crypto:encrypt-compositions')->assertFailed();

        $this->assertSame($before, $this->rawLines($versionId));
        $this->assertStringNotContainsString(CompositionCipher::PREFIX, $this->rawLines($versionId));
    }
}
