<?php

namespace App\Actions\Crypto;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\FormulaVersion;
use App\Models\Tenant;
use App\Services\Crypto\CompositionCipher;
use App\Services\Crypto\TenantKeyring;
use App\Support\CanonicalJson;
use App\Support\Crypto\Aead;
use App\Support\Crypto\MasterKeys;
use App\Support\DTOs\CompositionEncryptionReport;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts the composition columns of one tenant's existing formula lines in place, version by version. Each version is verified:
 * its content hash, rebuilt from the plaintext before and from the decrypted rows after, must be identical to the recorded one,
 * otherwise that version's change is rolled back. A dry run writes nothing (not even the tenant key) and only checks.
 */
final class EncryptTenantCompositionsAction
{
    /** @var list<string> */
    private const COLUMNS = ['trade_material_id', 'percent_ww', 'addition_temp_c'];

    public function __construct(
        private TenantKeyring $keyring,
        private CompositionCipher $cipher,
        private MasterKeys $masterKeys,
    ) {}

    public function __invoke(Tenant $tenant, bool $dryRun): CompositionEncryptionReport
    {
        $tenantId = (string) $tenant->getKey();
        $report = new CompositionEncryptionReport($dryRun);
        $versionIds = DB::table('formula_versions')->join('formulas', 'formulas.id', '=', 'formula_versions.formula_id')
            ->where('formulas.tenant_id', $tenantId)->orderBy('formula_versions.id')->pluck('formula_versions.id');

        foreach ($versionIds as $versionId) {
            $report = $report->merge($this->version($tenant, (string) $versionId, $dryRun));
        }

        return $report;
    }

    private function version(Tenant $tenant, string $versionId, bool $dryRun): CompositionEncryptionReport
    {
        $tenantId = (string) $tenant->getKey();
        $rows = DB::table('formula_lines')->where('formula_version_id', $versionId)->orderBy('line_no')->get();
        $plain = 0;
        $sealed = 0;
        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $value = $row->{$column};
                if ($value === null) {
                    continue;
                }
                CompositionCipher::isSealed((string) $value) ? $sealed++ : $plain++;
            }
        }
        $scanned = new CompositionEncryptionReport($dryRun, versionsScanned: 1, columnsAlreadyEncrypted: $sealed);
        if ($plain === 0) {
            return $scanned;
        }
        $pending = $scanned->merge(new CompositionEncryptionReport($dryRun, versionsPending: 1));

        $recorded = (string) DB::table('formula_versions')->where('id', $versionId)->value('hash');
        $before = $this->contentHash($versionId, allowLegacyPlaintext: true);
        if (! hash_equals($recorded, $before)) {
            return $pending->merge($this->failure($versionId, __('crypto.command.encrypt_hash_mismatch')));
        }

        if ($dryRun) {
            $this->masterKeys->current();
            $key = $this->keyring->existingDataKey($tenant) ?? Aead::generateKey();
            foreach ($rows as $row) {
                foreach (self::COLUMNS as $column) {
                    $value = $row->{$column};
                    if ($value !== null && ! CompositionCipher::isSealed((string) $value)
                        && Aead::open(Aead::seal((string) $value, $key, 'dry-run'), $key, 'dry-run') !== (string) $value) {
                        return $pending->merge($this->failure($versionId, __('exceptions.crypto_decrypt_failed', ['column' => $column])));
                    }
                }
            }

            return $pending;
        }

        // The key is created (and committed) before the version's transaction, so a rollback cannot orphan a cached key.
        $this->keyring->dataKey($tenantId);
        try {
            $columns = DB::transaction(fn (): int => $this->encryptRows($tenantId, $versionId, $before));
        } catch (CompositionCryptoException $exception) {
            return $pending->merge($this->failure($versionId, $exception->getMessage()));
        }

        return $pending->merge(new CompositionEncryptionReport($dryRun, versionsEncrypted: 1, columnsEncrypted: $columns));
    }

    /** @throws CompositionCryptoException When the content hash after sealing differs from the one before, or a column was left in plaintext. */
    private function encryptRows(string $tenantId, string $versionId, string $hashBefore): int
    {
        // Lets the approved-lines guard accept this one kind of change: only the encrypted columns of a row, in this transaction.
        DB::statement("select set_config('fl.reencrypt', 'on', true)");
        $columns = 0;
        foreach (DB::table('formula_lines')->where('formula_version_id', $versionId)->get() as $row) {
            $update = [];
            foreach (self::COLUMNS as $column) {
                $value = $row->{$column};
                if ($value !== null && ! CompositionCipher::isSealed((string) $value)) {
                    $update[$column] = $this->cipher->seal((string) $value, $tenantId, CompositionCipher::context('formula_lines', $column));
                    $columns++;
                }
            }
            if ($update !== []) {
                DB::table('formula_lines')->where('id', $row->id)->update($update);
            }
        }

        // Close the exemption at once: inside an outer transaction (tests, a wrapping caller) the setting would otherwise outlive this method.
        DB::statement("select set_config('fl.reencrypt', 'off', true)");

        $left = DB::table('formula_lines')->where('formula_version_id', $versionId)->get()->contains(
            fn (object $row): bool => collect(self::COLUMNS)->contains(fn (string $column): bool => $row->{$column} !== null && ! CompositionCipher::isSealed((string) $row->{$column})),
        );
        if ($left || ! hash_equals($hashBefore, $this->contentHash($versionId, allowLegacyPlaintext: false))) {
            throw CompositionCryptoException::verificationFailed($versionId);
        }

        return $columns;
    }

    private function contentHash(string $versionId, bool $allowLegacyPlaintext): string
    {
        $previous = config('crypto.allow_legacy_plaintext');
        config(['crypto.allow_legacy_plaintext' => $allowLegacyPlaintext]);
        try {
            return CanonicalJson::hash(FormulaVersion::query()->findOrFail($versionId)->canonicalContent());
        } finally {
            config(['crypto.allow_legacy_plaintext' => $previous]);
        }
    }

    private function failure(string $versionId, string $reason): CompositionEncryptionReport
    {
        return new CompositionEncryptionReport(false, failures: [['version' => $versionId, 'reason' => $reason]]);
    }
}
