<?php

namespace App\Actions\Packaging;

use App\Models\PackagingCompatibility;

/** Withdraws a declared pair; the audit trail keeps what it was. A BOM that relied on it is refused from then on. */
final class DeletePackagingCompatibilityAction
{
    public function __invoke(PackagingCompatibility $pair): void
    {
        $pair->delete();
    }
}
