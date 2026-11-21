/**
 * The bridge between the tax panel and the engine's per-product tax (resolveProductTax, applyProductTax). The browser decides
 * and computes nothing: the rate check against the dated regime, the tax line and the price with tax all come from the engine.
 * Setting values are the server's select values: "disabled", "rate:<rate>", or empty while nothing is chosen.
 */
import { applyProductTax, isDecimalString, priceLeaf, resolveProductTax } from '@fl/engine';
import { normalizeDecimalText } from './engine';

const RATE_PREFIX = 'rate:';

/** A select value as the engine's TaxSetting; undefined while nothing is chosen (the engine then says it is missing). */
export function taxSettingOf(value) {
    if (!value) {
        return undefined;
    }

    return value.startsWith(RATE_PREFIX) ? { mode: 'rate', ratePct: value.slice(RATE_PREFIX.length) } : { mode: value };
}

/**
 * What the engine says about the product's tax, and, for a price typed without tax, the tax line and the price with tax.
 * Returns { state: 'not_set' | 'disabled' | 'applied' | 'missing', ratePct?, missing, warnings, taxAmount?, priceWithTax? }:
 * `taxAmount` is null when tax is disabled (no line at all), a traced figure (even a zero one) when a rate applies.
 */
export function previewTax({ setting, regimes, familyId, taxType, asOf, price }) {
    const parsed = taxSettingOf(setting);
    const resolved = resolveProductTax(parsed, regimes, { productFamilyId: familyId, taxType, asOf }, {});

    if (!resolved.ok) {
        return { state: parsed ? 'missing' : 'not_set', missing: resolved.missing, warnings: resolved.warnings };
    }

    const tax = resolved.value;
    const base = { state: tax.state, ratePct: tax.state === 'applied' ? tax.regime.ratePct : null, regime: tax.state === 'applied' ? tax.regime : null, missing: [], warnings: resolved.warnings };
    const text = normalizeDecimalText(price);

    if (!isDecimalString(text) || text === '') {
        return base;
    }

    return { ...base, ...applyProductTax(priceLeaf('Price before tax', text), tax) };
}
