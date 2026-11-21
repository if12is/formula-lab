<?php

namespace App\Services\Material;

use App\Actions\Material\CreatePriceAction;
use App\Actions\Material\DeletePriceAction;
use App\Enums\Confidence;
use App\Models\Price;
use App\Models\TradeMaterial;

/** The single write path of reference prices. */
final class PriceService
{
    public function __construct(
        private CreatePriceAction $createPrice,
        private DeletePriceAction $deletePrice,
        private SourceService $sourceService,
    ) {}

    /**
     * @param  array{amount: string, quoted_on: string, source_id: string, confidence: string}  $data
     */
    public function add(TradeMaterial $material, array $data, ?int $userId = null): Price
    {
        return ($this->createPrice)($material, $data, $userId);
    }

    /**
     * A price typed without a date or source (the formula editor, D-09): dated by its entry day, pointed at the
     * shared "unverified entry" source and stored as an estimate (L7), which the engine refuses to cost with.
     */
    public function addUnverifiedEstimate(TradeMaterial $material, string $amount, ?int $userId = null): Price
    {
        return ($this->createPrice)($material, [
            'amount' => $amount,
            'quoted_on' => now()->toDateString(),
            'source_id' => $this->sourceService->unverifiedEntry()->getKey(),
            'confidence' => Confidence::L7Estimate,
        ], $userId);
    }

    public function remove(Price $price): void
    {
        ($this->deletePrice)($price);
    }
}
