<?php

namespace App\Casts;

use App\Exceptions\Domain\CompositionCryptoException;
use App\Models\Contracts\EncryptsComposition;
use App\Services\Crypto\CompositionCipher;
use App\Support\DecimalNormalizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A composition value kept encrypted at rest (U-02) and always a plain string in PHP: a decimal ("decimal", like DecimalString,
 * floats refused) or a row id ("uuid"). Assigning keeps the plaintext in memory until the model is saved (HasEncryptedComposition seals it);
 * a stored value that is not sealed is refused, never read as plaintext (unless config crypto.allow_legacy_plaintext is on during the migration window).
 *
 * @implements CastsAttributes<string|null, string|int|null>
 */
class EncryptedComposition implements CastsAttributes
{
    public const DECIMAL = 'decimal';

    public const UUID = 'uuid';

    public function __construct(private string $kind = self::DECIMAL) {}

    public static function context(Model $model, string $column): string
    {
        return CompositionCipher::context($model->getTable(), $column);
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (CompositionCipher::isSealed($value)) {
            return app(CompositionCipher::class)->open($value, $this->tenantId($model), self::context($model, $key));
        }
        // Not yet stored: the caller's own just-assigned plaintext (a new model, or a value changed and not saved).
        if (! $model->exists || $model->getRawOriginal($key) !== $value) {
            return $value;
        }
        if (config('crypto.allow_legacy_plaintext')) {
            return $value;
        }

        throw CompositionCryptoException::notEncrypted(self::context($model, $key));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($this->kind === self::UUID) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("{$key} must be a material id string, got ".get_debug_type($value));
            }

            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_string($value) || ! DecimalNormalizer::isValid($value)) {
            throw new InvalidArgumentException("{$key} must be a plain decimal string, got ".get_debug_type($value));
        }

        return $value;
    }

    private function tenantId(Model $model): string
    {
        if (! $model instanceof EncryptsComposition) {
            throw new InvalidArgumentException($model::class.' must implement '.EncryptsComposition::class);
        }

        return $model->compositionTenantId();
    }
}
