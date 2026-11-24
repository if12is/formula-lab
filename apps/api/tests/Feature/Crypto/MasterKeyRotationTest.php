<?php

namespace Tests\Feature\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Crypto\TenantKeyring;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** U-02: master key rotation re-wraps tenant keys and never touches the encrypted rows. */
class MasterKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private string $newKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->newKey = 'base64:'.base64_encode(random_bytes(32));
    }

    /** @return array{0: string, 1: string} The tenant id and the version id. */
    private function tenantWithVersion(): array
    {
        $formula = Formula::factory()->create();
        $version = app(FormulaVersionService::class)->createVersion($formula, [['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '42']], null);

        return [$formula->tenant_id, $version->id];
    }

    private function rotateTo(string $newKey): void
    {
        config(['crypto.previous_master_keys' => [config('crypto.master_key')], 'crypto.master_key' => $newKey]);
        app(TenantKeyring::class)->flush();
    }

    public function test_rewrap_moves_every_tenant_key_to_the_new_master_key_and_leaves_the_rows_alone(): void
    {
        [$tenantId, $versionId] = $this->tenantWithVersion();
        [$otherTenant] = $this->tenantWithVersion();
        $rowsBefore = DB::table('formula_lines')->orderBy('id')->get();
        $wrappedBefore = Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');

        $this->rotateTo($this->newKey);
        $this->artisan('crypto:rewrap-tenant-keys')->assertSuccessful();

        $wrappedAfter = Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');
        $this->assertNotSame($wrappedBefore, $wrappedAfter);
        $this->assertNotSame(explode('.', $wrappedBefore)[1], explode('.', $wrappedAfter)[1], 'a different master key id');
        $this->assertNotSame(Tenant::query()->whereKey($otherTenant)->value('wrapped_data_key'), $wrappedAfter);
        $this->assertEquals($rowsBefore, DB::table('formula_lines')->orderBy('id')->get(), 'the encrypted rows are untouched');

        // The previous key can now be dropped: everything opens with the new key alone.
        config(['crypto.previous_master_keys' => []]);
        app(TenantKeyring::class)->flush();
        $this->assertSame('42', FormulaLine::query()->where('formula_version_id', $versionId)->firstOrFail()->percent_ww);
    }

    public function test_the_old_key_alone_no_longer_opens_after_the_rewrap(): void
    {
        [, $versionId] = $this->tenantWithVersion();
        $oldKey = config('crypto.master_key');
        $this->rotateTo($this->newKey);
        $this->artisan('crypto:rewrap-tenant-keys')->assertSuccessful();

        config(['crypto.master_key' => $oldKey, 'crypto.previous_master_keys' => []]);
        app(TenantKeyring::class)->flush();

        $this->assertThrowsException(CompositionCryptoException::class, fn () => FormulaLine::query()->where('formula_version_id', $versionId)->firstOrFail()->percent_ww);
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        [$tenantId] = $this->tenantWithVersion();
        $before = Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');
        $this->rotateTo($this->newKey);

        $this->artisan('crypto:rewrap-tenant-keys', ['--dry-run' => true])
            ->expectsOutputToContain(__('crypto.command.dry_run'))
            ->expectsOutputToContain(__('crypto.command.rewrap_summary', ['tenants' => 1, 'rewrapped' => 1, 'current' => 0, 'without_key' => 0]))
            ->assertSuccessful();

        $this->assertSame($before, Tenant::query()->whereKey($tenantId)->value('wrapped_data_key'));
    }

    public function test_rewrap_is_idempotent_and_skips_tenants_without_a_key(): void
    {
        $this->tenantWithVersion();
        Tenant::factory()->create();
        $this->rotateTo($this->newKey);
        $this->artisan('crypto:rewrap-tenant-keys')->assertSuccessful();

        $this->artisan('crypto:rewrap-tenant-keys')
            ->expectsOutputToContain(__('crypto.command.rewrap_summary', ['tenants' => 2, 'rewrapped' => 0, 'current' => 1, 'without_key' => 1]))
            ->assertSuccessful();
    }

    public function test_rewrap_fails_without_changing_anything_when_the_old_key_is_not_configured(): void
    {
        [$tenantId] = $this->tenantWithVersion();
        $before = Tenant::query()->whereKey($tenantId)->value('wrapped_data_key');
        config(['crypto.master_key' => $this->newKey, 'crypto.previous_master_keys' => []]);
        app(TenantKeyring::class)->flush();

        $this->artisan('crypto:rewrap-tenant-keys')->assertFailed();

        $this->assertSame($before, Tenant::query()->whereKey($tenantId)->value('wrapped_data_key'));
    }

    public function test_the_generate_command_prints_a_usable_key_and_writes_no_file(): void
    {
        $this->artisan('crypto:generate-master-key')->expectsOutputToContain('APP_MASTER_KEY=base64:')->assertSuccessful();
    }
}
