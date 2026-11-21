<?php

namespace App\Support\Sanad;

use App\Exceptions\Domain\SanadIntegrationException;

/** Guards the "decimals are strings, never floats" rule of the contract on outgoing payloads. */
final class NoFloats
{
    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws SanadIntegrationException When a float is found anywhere in the payload.
     */
    public static function assert(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $here = $path === '' ? (string) $key : "{$path}.{$key}";
            if (is_float($value)) {
                throw SanadIntegrationException::floatNotAllowed($here);
            }
            if (is_array($value)) {
                self::assert($value, $here);
            }
        }
    }
}
