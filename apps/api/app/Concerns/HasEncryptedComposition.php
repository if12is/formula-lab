<?php

namespace App\Concerns;

use App\Casts\EncryptedComposition;
use App\Models\Contracts\EncryptsComposition;
use App\Services\Crypto\CompositionCipher;
use Illuminate\Database\Eloquent\Model;

/**
 * Seals the encrypted columns of a model just before every save, so a row never reaches the database (or the audit log, which
 * records the stored attributes) in plaintext. Reading goes through the EncryptedComposition cast.
 *
 * @mixin Model
 */
trait HasEncryptedComposition
{
    public static function bootHasEncryptedComposition(): void
    {
        static::saving(function (Model&EncryptsComposition $model): void {
            $cipher = app(CompositionCipher::class);
            foreach ($model->encryptedColumns() as $column) {
                $value = $model->getAttributes()[$column] ?? null;
                if ($value === null || CompositionCipher::isSealed((string) $value)) {
                    continue;
                }
                if ($model->exists && ! $model->isDirty($column)) {
                    continue;
                }
                $model->setRawAttributes([...$model->getAttributes(), $column => $cipher->seal((string) $value, $model->compositionTenantId(), EncryptedComposition::context($model, $column))], false);
            }
        });
    }
}
