<?php

namespace App\Services\Material;

use App\Actions\Material\CreateTradeMaterialAction;
use App\Actions\Material\UpdateTradeMaterialAction;
use App\Enums\TradeMaterialStatus;
use App\Models\Price;
use App\Models\TradeMaterial;
use Illuminate\Support\Facades\DB;

/** The single write path of trade materials. */
final class MaterialService
{
    public function __construct(
        private CreateTradeMaterialAction $createTradeMaterial,
        private UpdateTradeMaterialAction $updateTradeMaterial,
        private PriceService $priceService,
    ) {}

    /**
     * @param  array{trade_name: string, grade?: string|null, function?: string|null, substance_id?: string|null, is_mixture?: bool, status: string}  $data
     */
    public function create(array $data): TradeMaterial
    {
        return ($this->createTradeMaterial)($data);
    }

    /**
     * @param  array{trade_name: string, grade?: string|null, function?: string|null, substance_id?: string|null, is_mixture?: bool, status: string}  $data
     */
    public function update(TradeMaterial $material, array $data): TradeMaterial
    {
        return ($this->updateTradeMaterial)($material, $data);
    }

    /**
     * A material created from inside the formula editor (D-09): always a draft, and a price typed with it is an
     * unverified estimate (L7), so the cost it feeds is flagged as not reliable until a dated, sourced price exists.
     *
     * @param  array{trade_name: string, grade?: string|null, function?: string|null, substance_id?: string|null, is_mixture?: bool}  $data
     * @return array{material: TradeMaterial, price: Price|null}
     */
    public function createDraftFromEditor(array $data, ?string $pricePerKg, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $pricePerKg, $userId): array {
            $material = ($this->createTradeMaterial)([...$data, 'status' => TradeMaterialStatus::Draft]);
            $price = $pricePerKg === null ? null : $this->priceService->addUnverifiedEstimate($material, $pricePerKg, $userId);

            return ['material' => $material, 'price' => $price];
        });
    }
}
