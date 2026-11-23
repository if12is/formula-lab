<?php

namespace App\Actions\Packaging;

use App\Models\PackagingCompatibility;

/** Records one declared neck finish pair. */
final class CreatePackagingCompatibilityAction
{
    /** @param  array{container_neck_finish: string, closure_neck_finish: string, source_id: string, confidence: string, note?: string|null}  $data */
    public function __invoke(array $data): PackagingCompatibility
    {
        return PackagingCompatibility::query()->create($data);
    }
}
