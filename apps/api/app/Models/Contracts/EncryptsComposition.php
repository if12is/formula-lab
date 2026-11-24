<?php

namespace App\Models\Contracts;

/** A model whose composition columns are encrypted per tenant (U-02); see App\Concerns\HasEncryptedComposition. */
interface EncryptsComposition
{
    /** The tenant whose data key seals this row's encrypted columns. */
    public function compositionTenantId(): string;

    /** @return list<string> */
    public function encryptedColumns(): array;
}
