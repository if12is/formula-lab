<?php

namespace App\Services\Packaging;

use App\Actions\Packaging\CreatePackagingCompatibilityAction;
use App\Actions\Packaging\DeletePackagingCompatibilityAction;
use App\Models\PackagingCompatibility;

/** The single write path of declared neck finish pairs. */
final class PackagingCompatibilityService
{
    public function __construct(
        private CreatePackagingCompatibilityAction $createPair,
        private DeletePackagingCompatibilityAction $deletePair,
    ) {}

    /** @param  array{container_neck_finish: string, closure_neck_finish: string, source_id: string, confidence: string, note?: string|null}  $data */
    public function declare(array $data): PackagingCompatibility
    {
        return ($this->createPair)($data);
    }

    public function withdraw(PackagingCompatibility $pair): void
    {
        ($this->deletePair)($pair);
    }
}
