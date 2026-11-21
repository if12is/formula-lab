<?php

namespace App\Services\Material;

use App\Actions\Material\CreatePropertyValueAction;
use App\Actions\Material\DeletePropertyValueAction;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;

/** The single write path of sourced property values on trade materials. */
final class PropertyValueService
{
    public function __construct(
        private CreatePropertyValueAction $createPropertyValue,
        private DeletePropertyValueAction $deletePropertyValue,
    ) {}

    /**
     * @param  array{property: string, value?: string|null, min?: string|null, max?: string|null, unit: string, source_id: string, confidence: string, valid_from: string, lot_no?: string|null}  $data
     */
    public function add(TradeMaterial $material, array $data, ?int $userId = null): PropertyValue
    {
        return ($this->createPropertyValue)($material, $data, $userId);
    }

    public function remove(PropertyValue $propertyValue): void
    {
        ($this->deletePropertyValue)($propertyValue);
    }
}
