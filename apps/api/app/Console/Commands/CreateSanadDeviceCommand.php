<?php

namespace App\Console\Commands;

use App\Actions\Sanad\CreateSanadDeviceAction;
use App\Enums\Sanad\SanadAbility;
use App\Models\Tenant;
use Illuminate\Console\Command;

class CreateSanadDeviceCommand extends Command
{
    protected $signature = 'sanad:device:create {name : Label of the Sanad install} {--tenant= : Tenant id (optional when there is exactly one tenant)} {--ability=* : Restrict the token to these abilities (default: all)}';

    protected $description = 'Register a Sanad install for the Formula Lab link and print its bearer token once';

    public function handle(CreateSanadDeviceAction $createDevice): int
    {
        $tenantId = $this->resolveTenantId();
        if ($tenantId === null) {
            return self::FAILURE;
        }

        $abilities = [];
        foreach ((array) $this->option('ability') as $value) {
            $ability = SanadAbility::tryFrom((string) $value);
            if ($ability === null) {
                $this->error(__('sanad.command.ability_unknown', ['ability' => $value]));

                return self::FAILURE;
            }
            $abilities[] = $ability;
        }

        $created = $createDevice((string) $this->argument('name'), $tenantId, $abilities);

        $this->info(__('sanad.command.created', ['name' => $created['device']->name]));
        $this->line($created['device']->getKey());
        $this->line(__('sanad.command.token_notice', ['token' => $created['token']]));

        return self::SUCCESS;
    }

    private function resolveTenantId(): ?string
    {
        $tenant = $this->option('tenant');
        if ($tenant !== null) {
            if (! Tenant::query()->whereKey($tenant)->exists()) {
                $this->error(__('sanad.command.tenant_unknown', ['tenant' => $tenant]));

                return null;
            }

            return (string) $tenant;
        }

        $tenants = Tenant::query()->limit(2)->pluck('id');
        if ($tenants->count() !== 1) {
            $this->error(__('sanad.command.tenant_unresolved'));

            return null;
        }

        return (string) $tenants->first();
    }
}
