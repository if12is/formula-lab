<?php

namespace App\Services\Tax;

use App\Enums\SourceKind;
use App\Enums\TaxMode;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Models\Formula;
use App\Models\TaxRegime;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Collection;

/**
 * Reads the tax of products: the options a product may choose, the dated regimes the engine checks the chosen rate
 * against, and the rate Sanad gets. No rate is written here: the allowed rates are config/tax.php and each one is
 * backed by a regime row (source, confidence, validity). Nothing is ever defaulted to a rate.
 */
final class TaxRegimeService
{
    /** Prefix of the select value of a rate: "rate:14". */
    public const RATE_PREFIX = 'rate:';

    public function asOf(): string
    {
        return now()->toDateString();
    }

    /** @return list<string> Allowed rates as plain decimal strings. */
    public function allowedRates(): array
    {
        return array_map(fn (string $rate): string => DecimalNormalizer::normalize($rate), config('tax.allowed_rates'));
    }

    /** @return list<array{value: string, label: string}> */
    public function settingOptions(): array
    {
        return [
            ['value' => TaxMode::Disabled->value, 'label' => __('tax.options.disabled')],
            ...array_map(fn (string $rate): array => ['value' => self::RATE_PREFIX.$rate, 'label' => __('tax.options.rate', ['rate' => $rate])], $this->allowedRates()),
        ];
    }

    /** @return list<string> */
    public function settingValues(): array
    {
        return array_column($this->settingOptions(), 'value');
    }

    /** The select value of a formula's tax: "disabled", "rate:<rate>", or null while none was chosen. */
    public function settingOf(Formula $formula): ?string
    {
        return match ($formula->tax_mode) {
            TaxMode::Disabled => TaxMode::Disabled->value,
            TaxMode::Rate => self::RATE_PREFIX.DecimalNormalizer::normalize((string) $formula->tax_rate_pct),
            null => null,
        };
    }

    /** The decimal rate of a formula in plain text, or null when tax is disabled or not chosen. */
    public function rateOf(Formula $formula): ?string
    {
        return $formula->tax_mode === TaxMode::Rate ? DecimalNormalizer::normalize((string) $formula->tax_rate_pct) : null;
    }

    /**
     * The regimes of the configured tax, as the engine's TaxRegime. A formula is its own product family: the regime is a
     * rate of this tax, valid for the dates it says, and the engine picks the one of the chosen rate in force on the price date.
     *
     * @return list<array<string, string>>
     */
    public function engineRegimes(string $productFamilyId): array
    {
        return $this->regimes()->map(fn (TaxRegime $regime): array => [
            'productFamilyId' => $productFamilyId,
            'taxType' => $regime->tax_type,
            'ratePct' => DecimalNormalizer::normalize((string) $regime->rate_pct),
            'validFrom' => $regime->valid_from->toDateString(),
            ...($regime->valid_to === null ? [] : ['validTo' => $regime->valid_to->toDateString()]),
            'sourceId' => $regime->source_id,
            'confidence' => $regime->confidence,
        ])->all();
    }

    /**
     * The tax of a product as the engine's price waterfall reads it (`resolveProductTax` input): the setting (absent while none was chosen,
     * never a default), the dated regimes of this tax, the tax type and the date. The engine checks the rate against the regimes.
     *
     * @return array<string, mixed>
     */
    public function engineTax(Formula $formula): array
    {
        $setting = match ($formula->tax_mode) {
            TaxMode::Disabled => ['mode' => 'disabled'],
            TaxMode::Rate => ['mode' => 'rate', 'ratePct' => (string) $this->rateOf($formula)],
            null => null,
        };

        return array_filter([
            'setting' => $setting,
            'regimes' => $this->engineRegimes((string) $formula->getKey()),
            'productFamilyId' => (string) $formula->getKey(),
            'taxType' => config('tax.type'),
            'asOf' => $this->asOf(),
        ], fn (mixed $part): bool => $part !== null);
    }

    /**
     * Props of the pricing/tax panel of the editor.
     *
     * @return array<string, mixed>
     */
    public function editorProps(Formula $formula): array
    {
        $regimes = $this->regimes();

        return [
            'setting' => $this->settingOf($formula),
            'options' => $this->settingOptions(),
            'taxType' => config('tax.type'),
            'asOf' => $this->asOf(),
            'regimes' => $this->engineRegimes((string) $formula->getKey()),
            'regimeInfo' => $regimes->map(fn (TaxRegime $regime): array => [
                'ratePct' => DecimalNormalizer::normalize((string) $regime->rate_pct),
                'validFrom' => $regime->valid_from->toDateString(),
                'validTo' => $regime->valid_to?->toDateString(),
                'verification' => $regime->verification,
                'confidence' => $regime->confidence,
                'sourceId' => $regime->source_id,
                'sourceTitle' => $regime->source->title,
                'sourceKind' => SourceKind::labelFor($regime->source->kind),
            ])->all(),
            'urls' => ['update' => route('formulas.tax.update', $formula, false)],
        ];
    }

    /**
     * The `tax_rate` of the Sanad product payload: null when the product's tax is disabled (no tax line), otherwise the
     * rate as a decimal string with the contract's places ("14.00", "0.00" for a 0 % product).
     *
     * @throws InvalidTaxSettingException When no tax was chosen, or a chosen rate has no regime in force today.
     */
    public function sanadTaxRate(Formula $formula): ?string
    {
        if ($formula->tax_mode === null) {
            throw InvalidTaxSettingException::settingMissing($formula->code);
        }

        $rate = $this->rateOf($formula);

        if ($rate === null) {
            return null;
        }

        $asOf = $this->asOf();
        $inForce = $this->regimes()->contains(fn (TaxRegime $regime): bool => DecimalNormalizer::normalize((string) $regime->rate_pct) === $rate
            && $regime->valid_from->toDateString() <= $asOf
            && ($regime->valid_to === null || $regime->valid_to->toDateString() >= $asOf));

        if (! $inForce) {
            throw InvalidTaxSettingException::regimeMissing($rate, $asOf);
        }

        return $this->withPlaces($rate, (int) config('tax.sanad_rate_places'));
    }

    /** @return Collection<int, TaxRegime> */
    private function regimes(): Collection
    {
        return TaxRegime::query()->with('source')->where('tax_type', config('tax.type'))->orderBy('rate_pct')->orderBy('valid_from')->get();
    }

    /** "14" -> "14.00": text padding only, no arithmetic. */
    private function withPlaces(string $rate, int $places): string
    {
        [$int, $frac] = array_pad(explode('.', $rate, 2), 2, '');

        return $places === 0 ? $int : $int.'.'.str_pad(substr($frac, 0, $places), $places, '0');
    }
}
