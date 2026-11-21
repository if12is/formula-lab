<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadAbility;
use App\Models\SanadDevice;
use Illuminate\Support\Str;

/** Registers a Sanad install and returns the plain bearer token once: only its sha256 is stored. */
final class CreateSanadDeviceAction
{
    /**
     * @param  list<SanadAbility>  $abilities
     * @return array{device: SanadDevice, token: string}
     */
    public function __invoke(string $name, string $tenantId, array $abilities = []): array
    {
        $token = config('sanad.inbound.device_token_prefix').Str::random(config('sanad.inbound.device_token_length'));

        $device = SanadDevice::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'token_hash' => SanadDevice::hashToken($token),
            'abilities' => array_map(
                fn (SanadAbility $ability): string => $ability->value,
                $abilities === [] ? SanadAbility::cases() : $abilities,
            ),
        ]);

        return ['device' => $device, 'token' => $token];
    }
}
