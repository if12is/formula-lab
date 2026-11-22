/**
 * The bridge between the price list screens and the engine's priceListLine (@fl/engine). The server shapes the input of a
 * line (a sourced unit cost, a margin rule or an agreed price, each with its source and confidence); the browser only runs it
 * through the engine and sorts what comes back. It never adds, subtracts or compares a price itself.
 */
import { priceListLine } from '@fl/engine';

/**
 * A line as the engine prices it.
 *   ok       true with price (the published, 2-decimal text), priceTraced (the engine's { value, unit, trace }), cost, margin,
 *            marginOnPricePct, markupOnCostPct and belowCost; or false with missing [{ property, reason }]
 *   warnings the engine's warnings (a weak source), as keys
 */
export function evaluateLine(engine) {
    const result = priceListLine({ unitCost: engine.unitCost, spec: engine.spec, policy: {} });

    if (!result.ok) {
        return { ok: false, missing: result.missing, warnings: result.warnings };
    }

    const value = result.value;

    return {
        ok: true,
        price: value.publishedPrice,
        priceTraced: value.price,
        cost: value.unitCost.value,
        margin: value.marginAmount.value,
        marginOnPricePct: value.marginOnPricePct,
        markupOnCostPct: value.markupOnCostPct,
        belowCost: value.belowCost,
        warnings: result.warnings,
    };
}

/**
 * The engine's line input for a form being filled in, so the modal can show the margin before anything is saved. A part the
 * form does not have yet is left out and the engine reports it missing.
 * `form` is { mode, rule_key, override_price, override_confidence }, `override` the chosen source ({ id, retrievedAt }).
 */
export function previewInput({ unitCost, rules, form, source }) {
    const engine = {};

    if (unitCost) {
        engine.unitCost = unitCost;
    }

    if (form.mode === 'rule') {
        const rule = rules.find((candidate) => candidate.key === form.rule_key)?.engine;

        engine.spec = rule ? { mode: 'rule', rule } : { mode: 'rule' };
    } else {
        const price = form.override_price !== '' && source?.id && form.override_confidence && source.retrievedAt
            ? { value: form.override_price, unit: 'EGP', sourceId: source.id, confidence: form.override_confidence, validFrom: source.retrievedAt }
            : undefined;

        engine.spec = price ? { mode: 'override', price } : { mode: 'override' };
    }

    return engine;
}

/**
 * sourceId -> { title } for the explain tree: the unit cost's source, an agreed price's source and each margin rule's source (the
 * rule's own text, the id the engine traces being "pricing-rule:<key>"). `rows` are line rows of the screen.
 */
export function priceSources(rows, rules) {
    const sources = {};

    for (const rule of rules) {
        if (rule.source) {
            sources[`pricing-rule:${rule.key}`] = { title: rule.source };
        }
    }

    for (const row of rows) {
        if (row.unit_cost?.source_id) {
            sources[row.unit_cost.source_id] = { title: row.unit_cost.source_title };
        }

        if (row.override?.source_id) {
            sources[row.override.source_id] = { title: row.override.source_title };
        }
    }

    return sources;
}
