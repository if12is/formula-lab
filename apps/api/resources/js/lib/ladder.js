/**
 * What the price ladder and the tiers screens share about the commercial inputs: the blank state of the margins and the payload the server takes.
 * The browser never works out a price or a margin: it only sends what was typed, and prints what the engine answered.
 */

/** Every rate blank: nothing is assumed (the engine lists each blank as missing). */
export const blankRates = () => ({
    margin_basis: undefined,
    factory_margin_pct: '',
    distribution_cost_per_unit: '',
    wholesaler_margin_pct: '',
    retailer_margin_pct: '',
    returns_pct: '',
    promo_pct: '',
});

/** The rates as the request takes them: a blank basis is left out. */
export const ratesPayload = (rates) => ({ ...rates, margin_basis: rates.margin_basis ?? null });

/** The dollar moves typed as "5, 10, -10" as a list of texts (a comma, the Arabic comma U+060C or a space separates; nothing is parsed as a number). */
export const splitList = (text) => String(text ?? '').split(/[,\u060C\s]+/).map((part) => part.trim()).filter((part) => part !== '');
