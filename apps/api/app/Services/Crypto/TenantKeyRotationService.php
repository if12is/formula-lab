<?php

namespace App\Services\Crypto;

use App\Actions\Crypto\RewrapTenantKeyAction;
use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Tenant;
use App\Support\DTOs\KeyRewrapReport;
use Illuminate\Support\Facades\DB;

/** Master key rotation: moves every tenant's wrapped data key onto the current master key (all-or-nothing). */
final class TenantKeyRotationService
{
    public function __construct(
        private RewrapTenantKeyAction $rewrap,
        private TenantKeyring $keyring,
    ) {}

    /** @throws CompositionCryptoException When any tenant's key cannot be opened with the configured master keys: nothing is changed. */
    public function rewrapAll(bool $dryRun): KeyRewrapReport
    {
        $report = DB::transaction(function () use ($dryRun): KeyRewrapReport {
            $tenants = 0;
            $rewrapped = 0;
            $current = 0;
            $without = 0;
            foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                $tenants++;
                if ((string) $tenant->getRawOriginal('wrapped_data_key') === '') {
                    $without++;

                    continue;
                }
                ($this->rewrap)($tenant, $dryRun) ? $rewrapped++ : $current++;
            }

            return new KeyRewrapReport($dryRun, $tenants, $rewrapped, $current, $without);
        });
        $this->keyring->flush();

        return $report;
    }
}
