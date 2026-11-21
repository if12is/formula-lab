<?php

namespace App\Actions\Sanad;

use App\Enums\Sanad\SanadItemKind;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\Formula;
use App\Services\Tax\TaxRegimeService;
use App\Support\DecimalNormalizer;

/**
 * The body of the Sanad product upsert (contract 4.2) for a formula's finished good. Money and rates are decimal strings.
 * `tax_rate` follows the product's tax setting: null when tax is disabled (no tax line), "0.00" for a 0 % product, "14.00"
 * for 14 %. A product with no tax chosen is refused here, never sent with a guessed rate.
 */
final class BuildFinishedGoodPayloadAction
{
    public function __construct(private readonly TaxRegimeService $tax) {}

    /**
     * @param  array{uom: string, cost_price: string, sell_price?: string|null, sku?: string|null, name_en?: string|null}  $details
     * @return array<string, mixed>
     *
     * @throws SanadLinkException When the unit is missing, or a price is not a decimal string.
     * @throws InvalidTaxSettingException When no tax was chosen, or the chosen rate has no regime in force.
     */
    public function __invoke(Formula $formula, string $productUuid, array $details): array
    {
        $uom = trim($details['uom']);
        if ($uom === '') {
            throw SanadLinkException::uomRequired();
        }

        $costPrice = $this->decimal('cost_price', $details['cost_price']);
        $sellPrice = ($details['sell_price'] ?? null) === null ? null : $this->decimal('sell_price', $details['sell_price']);

        return [
            'uuid' => $productUuid,
            'name' => $formula->name,
            'name_en' => $details['name_en'] ?? null,
            'sku' => $details['sku'] ?? $formula->code,
            'item_kind' => SanadItemKind::FinishedGood->value,
            'is_sellable' => true,
            'uom' => $uom,
            'cost_price' => $costPrice,
            'sell_price' => $sellPrice,
            'tax_rate' => $this->tax->sanadTaxRate($formula),
            'external_id' => (string) $formula->getKey(),
        ];
    }

    /** @throws SanadLinkException */
    private function decimal(string $field, string $value): string
    {
        if (! DecimalNormalizer::isValid($value) || str_starts_with($value, '-')) {
            throw SanadLinkException::decimalInvalid($field);
        }

        return $value;
    }
}
