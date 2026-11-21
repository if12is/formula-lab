<?php

namespace App\Http\Middleware;

use App\Enums\Sanad\SanadAbility;
use App\Models\SanadDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for /api/sanad/v1/*: 401 for a missing or unknown token, 403 when the device lacks the
 * ability (`sanad.device:outbox:read`) or the token is bound to a different Sanad install (X-Device-Uuid).
 * The device is exposed as the `sanad_device` request attribute.
 */
class AuthenticateSanadDevice
{
    public const ATTRIBUTE = 'sanad_device';

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $token = (string) $request->bearerToken();
        if ($token === '') {
            return $this->reject('unauthenticated', 401);
        }

        $hash = SanadDevice::hashToken($token);
        $device = SanadDevice::query()->where('token_hash', $hash)->first();
        if ($device === null || ! hash_equals($device->token_hash, $hash)) {
            return $this->reject('unauthenticated', 401);
        }

        $requiredAbility = $ability === null ? null : SanadAbility::from($ability);
        if ($requiredAbility !== null && ! $device->hasAbility($requiredAbility)) {
            return $this->reject('ability_missing', 403);
        }

        $sanadDeviceUuid = $request->header('X-Device-Uuid');
        if ($sanadDeviceUuid !== null) {
            if (! Str::isUuid($sanadDeviceUuid)) {
                return $this->reject('device_uuid_invalid', 400);
            }
            $sanadDeviceUuid = Str::lower($sanadDeviceUuid);

            if ($device->sanad_device_uuid === null) {
                $device->sanad_device_uuid = $sanadDeviceUuid;
            } elseif ($device->sanad_device_uuid !== $sanadDeviceUuid) {
                return $this->reject('device_mismatch', 403);
            }
        }

        $device->last_seen_at = now();
        $device->saveQuietly();

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    private function reject(string $key, int $status): Response
    {
        return response()->json(['message' => __('sanad.'.$key)], $status);
    }
}
