<?php

namespace App\Services\Fragrance;

use App\Models\IfraCertificate;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\SanadSupplier;
use App\Models\TradeMaterial;

/**
 * The fragrance-oil view of a trade material (L-01): what the oil is made of as data (supplier, IFRA certificates with the
 * limit of each category, density, flash point, price) and which of those are still missing. Nothing here is a regulatory
 * value: a limit is the one written on a certificate, and a missing one is shown as missing.
 */
final class FragranceProfileService
{
    /** The facts a fragrance oil needs on file, in the order the page lists them. */
    public const CHECKS = ['supplier', 'certificate', 'ifra_limits', 'density', 'flash_point', 'price'];

    /**
     * @return array{supplier: string|null, certificates: list<array<string, mixed>>, checks: list<array{key: string, present: bool}>, complete: bool}
     */
    public function profile(TradeMaterial $material): array
    {
        $certificates = $material->ifraCertificates()->with(['source', 'limits'])->get();
        $supplier = $material->sanad_supplier_uuid === null ? null : SanadSupplier::query()->whereKey($material->sanad_supplier_uuid)->value('name');
        $present = [
            'supplier' => $material->sanad_supplier_uuid !== null,
            'certificate' => $certificates->isNotEmpty(),
            'ifra_limits' => $certificates->contains(fn (IfraCertificate $certificate): bool => $certificate->limits->isNotEmpty()),
            'density' => $this->hasProperty($material, 'density'),
            'flash_point' => $this->hasProperty($material, 'flash_point'),
            'price' => Price::query()->where('trade_material_id', $material->getKey())->exists(),
        ];
        $checks = array_map(fn (string $key): array => ['key' => $key, 'present' => $present[$key]], self::CHECKS);

        return [
            'supplier' => $supplier,
            'certificates' => $certificates->map(fn (IfraCertificate $certificate): array => [
                'id' => $certificate->getKey(),
                'certificate_number' => $certificate->certificate_number,
                'ifra_amendment' => $certificate->ifra_amendment,
                'issuer' => $certificate->issuer,
                'issued_on' => $certificate->issued_on->toDateString(),
                'source_id' => $certificate->source_id,
                'source_title' => $certificate->source->title,
                'confidence' => $certificate->confidence->value,
                'limits' => $certificate->limits->map(fn (PropertyValue $limit): array => [
                    'id' => $limit->getKey(),
                    'category' => $limit->category,
                    'value' => $limit->value,
                    'unit' => $limit->unit,
                ])->values()->all(),
            ])->values()->all(),
            'checks' => $checks,
            'complete' => ! in_array(false, $present, true),
        ];
    }

    private function hasProperty(TradeMaterial $material, string $property): bool
    {
        return PropertyValue::query()
            ->where('property', $property)
            ->where(function ($query) use ($material): void {
                $query->where(fn ($own) => $own->where('entity_type', 'trade_material')->where('entity_id', $material->getKey()));

                if ($material->substance_id !== null) {
                    $query->orWhere(fn ($inherited) => $inherited->where('entity_type', 'substance')->where('entity_id', $material->substance_id));
                }
            })
            ->exists();
    }
}
