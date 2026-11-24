<?php

namespace Tests\Feature\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\AuditLog;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Crypto\CompositionCipher;
use App\Services\Crypto\TenantKeyring;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** U-02: composition columns are sealed with the tenant's key; the plaintext never reaches the database. */
class CompositionEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    private TradeMaterial $secret;

    private TradeMaterial $water;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formula = Formula::factory()->create();
        $this->secret = TradeMaterial::factory()->create(['trade_name' => 'Zephyrine Secret Surfactant']);
        $this->water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
    }

    private function version(?Formula $formula = null): FormulaVersion
    {
        return app(FormulaVersionService::class)->createVersion($formula ?? $this->formula, [
            ['trade_material_id' => $this->secret->id, 'percent_ww' => '17.250', 'phase' => 'A', 'addition_temp_c' => '65.5'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
    }

    public function test_composition_round_trips_through_the_model(): void
    {
        $version = $this->version();

        $line = FormulaLine::query()->where('formula_version_id', $version->id)->where('line_no', 1)->firstOrFail();
        $this->assertSame($this->secret->id, $line->trade_material_id);
        $this->assertSame('17.250', $line->percent_ww);
        $this->assertSame('65.5', $line->addition_temp_c);
        $this->assertSame($this->secret->id, $line->tradeMaterial->id, 'the relation resolves through the decrypted key');
        $this->assertNull(FormulaLine::query()->where('formula_version_id', $version->id)->where('line_no', 2)->firstOrFail()->addition_temp_c);
        $this->assertTrue($version->fresh()->verifyHash());
    }

    public function test_the_raw_database_never_contains_the_ingredient_or_its_percentage(): void
    {
        $version = $this->version();
        $this->assertNotEmpty($version->lines);

        $rows = DB::table('formula_lines')->where('formula_version_id', $version->id)->get();
        $raw = json_encode($rows).DB::selectOne('select string_agg(t::text, \' \') as dump from formula_lines t where formula_version_id = ?', [$version->id])->dump;

        $this->assertStringNotContainsString($this->secret->id, $raw);
        $this->assertStringNotContainsString($this->water->id, $raw);
        $this->assertStringNotContainsString('Zephyrine', $raw);
        $this->assertStringNotContainsString('17.25', $raw);
        $this->assertStringNotContainsString('65.5', $raw);
        $first = $rows->firstWhere('line_no', 1);
        foreach (['trade_material_id', 'percent_ww', 'addition_temp_c'] as $column) {
            $this->assertStringStartsWith(CompositionCipher::PREFIX, $first->{$column});
        }
        $this->assertNull($rows->firstWhere('line_no', 2)->percent_ww, 'a q.s. line has no percentage to hide');
    }

    public function test_the_audit_log_records_ciphertext_not_the_composition(): void
    {
        $version = $this->version();
        $line = $version->lines->first();
        $line->update(['percent_ww' => '18.5']);

        $audit = json_encode(AuditLog::query()->where('table_name', 'formula_lines')->where('record_id', $line->id)->get(['before', 'after']));
        $this->assertStringNotContainsString($this->secret->id, $audit);
        $this->assertStringNotContainsString('17.25', $audit);
        $this->assertStringNotContainsString('18.5', $audit);
        $this->assertStringContainsString(CompositionCipher::PREFIX, $audit);
    }

    public function test_the_version_hash_is_computed_on_the_plaintext_canonical_form(): void
    {
        $version = $this->version();
        $plain = FormulaVersion::hashLines([
            ['lineNo' => '1', 'tradeMaterialId' => $this->secret->id, 'percentWw' => '17.25', 'isQs' => false, 'phase' => 'A', 'additionTempC' => '65.5'],
            ['lineNo' => '2', 'tradeMaterialId' => $this->water->id, 'percentWw' => null, 'isQs' => true, 'phase' => null],
        ]);

        $this->assertSame($plain, $version->hash);
        $this->assertTrue($version->fresh()->verifyHash());
    }

    public function test_a_changed_ciphertext_is_detected_and_never_read_as_a_value(): void
    {
        $version = $this->version();
        $line = $version->lines->first();
        $stored = DB::table('formula_lines')->where('id', $line->id)->value('percent_ww');
        $flipped = substr($stored, 0, -2).(str_ends_with($stored, 'A') ? 'B' : 'A').substr($stored, -1);
        DB::table('formula_lines')->where('id', $line->id)->update(['percent_ww' => $flipped]);

        $this->assertThrowsException(CompositionCryptoException::class, fn () => $line->fresh()->percent_ww, __('exceptions.crypto_decrypt_failed', ['column' => 'formula_lines.percent_ww']));
        $this->assertFalse($version->fresh()->verifyHash());
    }

    public function test_a_plaintext_value_written_straight_into_the_database_is_refused(): void
    {
        $version = $this->version();
        $line = $version->lines->first();
        DB::table('formula_lines')->where('id', $line->id)->update(['percent_ww' => '99']);

        $this->assertThrowsException(CompositionCryptoException::class, fn () => $line->fresh()->percent_ww, __('exceptions.crypto_not_encrypted', ['column' => 'formula_lines.percent_ww']));
        $this->assertFalse($version->fresh()->verifyHash());
    }

    public function test_a_value_copied_to_another_column_does_not_open(): void
    {
        $version = $this->version();
        $line = $version->lines->first();
        DB::table('formula_lines')->where('id', $line->id)->update(['addition_temp_c' => DB::table('formula_lines')->where('id', $line->id)->value('percent_ww')]);

        $this->assertThrowsException(CompositionCryptoException::class, fn () => $line->fresh()->addition_temp_c);
    }

    public function test_another_tenants_key_cannot_open_the_composition(): void
    {
        $version = $this->version();
        $other = $this->version(Formula::factory()->create());
        $stolen = DB::table('formula_lines')->where('formula_version_id', $version->id)->where('line_no', 1)->value('percent_ww');
        DB::table('formula_lines')->where('formula_version_id', $other->id)->where('line_no', 1)->update(['percent_ww' => $stolen]);

        $this->assertNotSame(
            Tenant::query()->whereKey($version->formula->tenant_id)->value('wrapped_data_key'),
            Tenant::query()->whereKey($other->formula->tenant_id)->value('wrapped_data_key'),
        );
        $this->assertThrowsException(CompositionCryptoException::class, fn () => FormulaLine::query()->where('formula_version_id', $other->id)->where('line_no', 1)->firstOrFail()->percent_ww);
        $this->assertSame('17.250', FormulaLine::query()->where('formula_version_id', $version->id)->where('line_no', 1)->firstOrFail()->percent_ww);
    }

    public function test_a_missing_master_key_raises_a_translated_error_and_never_stores_plaintext(): void
    {
        $version = $this->version();
        config(['crypto.master_key' => null]);
        app(TenantKeyring::class)->flush();

        $this->assertThrowsException(CompositionCryptoException::class, fn () => FormulaLine::query()->where('formula_version_id', $version->id)->firstOrFail()->percent_ww, __('exceptions.crypto_master_key_missing'));
        $this->assertThrowsException(CompositionCryptoException::class, fn () => $this->version(Formula::factory()->create()), __('exceptions.crypto_master_key_missing'));
        $this->assertSame(1, FormulaVersion::query()->count(), 'the failed save left nothing behind');
        $this->assertSame(2, DB::table('formula_lines')->count());
    }

    public function test_a_malformed_master_key_is_refused(): void
    {
        $version = $this->version();
        config(['crypto.master_key' => 'base64:'.base64_encode('too short')]);
        app(TenantKeyring::class)->flush();

        $this->assertThrowsException(CompositionCryptoException::class, fn () => $version->fresh()->lines->first()->percent_ww, __('exceptions.crypto_master_key_invalid'));
    }

    public function test_a_different_master_key_cannot_open_the_tenant_key(): void
    {
        $version = $this->version();
        config(['crypto.master_key' => 'base64:'.base64_encode(random_bytes(32))]);
        app(TenantKeyring::class)->flush();

        $this->assertThrowsException(CompositionCryptoException::class, fn () => $version->fresh()->lines->first()->percent_ww);
    }

    public function test_the_error_messages_exist_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (['master_key_missing', 'master_key_invalid', 'master_key_unknown', 'tenant_unknown', 'decrypt_failed', 'not_encrypted', 'verification_failed'] as $key) {
                $this->assertNotSame("exceptions.crypto_{$key}", trans("exceptions.crypto_{$key}", [], $locale));
            }
        }
    }

    public function test_a_draft_line_can_be_edited_and_stays_encrypted(): void
    {
        $line = $this->version()->lines->first();
        $line->update(['percent_ww' => '20']);

        $this->assertSame('20', $line->fresh()->percent_ww);
        $this->assertStringStartsWith(CompositionCipher::PREFIX, DB::table('formula_lines')->where('id', $line->id)->value('percent_ww'));
        $this->assertStringStartsWith(CompositionCipher::PREFIX, DB::table('formula_lines')->where('id', $line->id)->value('trade_material_id'));
    }
}
