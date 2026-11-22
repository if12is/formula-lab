/**
 * The bridge between the editor screens and the deterministic engine (@fl/engine). The browser never does
 * arithmetic on a formula or a price: every figure shown comes out of the engine (draftSummary, balanceQs,
 * calculate, resolveValue). This file only prepares what the engine takes and sorts what it gives back.
 */
import { ENGINE_VERSION, balanceQs, calculate, convertFromEgp, convertMoney, draftSummary, inputLeaf, isDecimalString, materialUsdExposure, phaseSummary, productCostBreakdown, qty, resolveValue, sourcedEgp, traced, validatePhases } from '@fl/engine';

/** Arabic-Indic and Persian digits, and the Arabic decimal separators, typed into a number field become plain ASCII text. */
export function normalizeDecimalText(text) {
    return String(text ?? '')
        .replace(/[\u0660-\u0669]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[\u06f0-\u06f9]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
        .replace(/[\u066b,]/g, '.')
        .replace(/\s+/g, '');
}

export { isDecimalString };

/** The id the engine sees for a line: its material, or a placeholder while none is chosen yet (never shared between lines). */
const engineId = (line) => line.material_id ?? `pending:${line.key}`;

/** A q.s. line without a share yet is the one the engine will fill in, so the sum leaves it out. */
const counted = (lines) => lines.filter((line) => !(line.is_qs && line.percent === ''));

/**
 * The sum of the percentages as an engine trace (engine `traced`): the sum is draftSummary's, the leaves are the lines
 * that hold a valid percentage, so the explain panel can show what the Σ is made of. Null when no line has a percentage.
 */
export function sumTraced(lines, labelOf, sum) {
    const leaves = lines
        .filter((line) => line.material_id && isDecimalString(line.percent))
        .map((line) => inputLeaf(`${labelOf(line.material_id)} percent`, line.percent, '%w/w'));

    return leaves.length ? traced('sum', 'Σ % w/w', sum, '%w/w', leaves) : null;
}

/**
 * The sum, its deviation from 100 and the problems per line, from the engine's draftSummary.
 * `issues` is keyed by line key: [{ code, messageKey, detail }].
 */
export function summarizeLines(lines) {
    const used = counted(lines);
    const summary = draftSummary(used.map((line) => ({ materialId: engineId(line), percentWW: line.percent })));
    const issues = {};

    for (const issue of summary.issues) {
        const owners = used.filter((line) => engineId(line) === issue.materialId);
        // A problem without a line (none today) would otherwise vanish; an empty key keeps it visible.
        for (const owner of owners.length ? owners : [{ key: '' }]) {
            (issues[owner.key] ??= []).push(issue);
        }
    }

    return { sum: summary.sum, deviation: summary.deviation, withinTolerance: summary.withinTolerance, issues, hasIssues: summary.issues.length > 0 };
}

/**
 * Balances the single q.s. line to 100 minus the others (engine balanceQs).
 * Returns { ok: true, percent, event } or { ok: false, errors: [{ messageKey, detail }] }.
 */
export function balanceLines(lines) {
    const result = balanceQs(lines.map((line) => ({
        materialId: engineId(line),
        percentWW: line.percent === '' ? undefined : line.percent,
        qs: line.is_qs,
    })));

    if (!result.ok) {
        return { ok: false, errors: result.errors };
    }

    return { ok: true, percent: result.value.balanceEvent.after, event: result.value.balanceEvent };
}

/**
 * The editor lines as the engine takes them ({ materialId, percentWW }). A q.s. line without a share is balanced first
 * (engine balanceQs) so cost and compliance can be previewed; the balanced share is never written back to the line.
 */
export function engineLines(lines) {
    const inputs = lines.map((line) => ({ materialId: line.material_id, percentWW: line.percent }));
    const qs = lines.find((line) => line.is_qs && line.percent === '');

    if (qs) {
        const balanced = balanceLines(lines);

        if (balanced.ok) {
            inputs.find((line) => line.materialId === qs.material_id).percentWW = balanced.percent;
        }
    }

    return inputs;
}

/**
 * The phases of the draft (M-01) as the engine judges them: the sum of each phase (phaseSummary) and what validatePhases
 * refuses. A q.s. line without a share is balanced first, like cost and compliance. `issues` is keyed by line key.
 * Phases are optional: with no phase on any line there is nothing to show (`active` false) and nothing is refused.
 */
export function phaseCheck(lines) {
    const balanced = engineLines(lines);
    const used = lines.filter((line) => line.material_id || line.percent !== '');
    const phased = used.map((line) => ({
        materialId: engineId(line),
        percentWW: balanced[lines.indexOf(line)].percentWW,
        phase: line.phase || null,
        additionTempC: line.addition_temp_c || null,
    }));
    const validation = validatePhases(phased);
    const issues = {};

    for (const issue of validation.ok ? [] : validation.errors) {
        for (const owner of used.filter((line) => engineId(line) === issue.materialId)) {
            (issues[owner.key] ??= []).push(issue);
        }
    }

    return {
        active: used.some((line) => line.phase),
        summary: validation.ok ? validation.value : phaseSummary(phased),
        issues,
        formulaIssues: validation.ok ? [] : validation.errors.filter((issue) => issue.materialId === undefined),
        ok: validation.ok,
    };
}

/**
 * The exchange rate the server chose for a price (App\Services\Currency\ExchangeRateLookupService: the latest rate dated on
 * or before the quote date) as the engine's FxRate, pinned to that quote date with `forDate` so the engine uses it for prices
 * of that day only. Undefined for an EGP price and for a foreign one the server found no rate for: the engine then reports
 * `fx:XXX->EGP` as missing. The browser neither picks nor computes a rate.
 */
function fxRateOf(currency, quotedOn, fx) {
    if (!fx || currency === 'EGP') {
        return undefined;
    }

    return {
        from: currency,
        to: 'EGP',
        rate: fx.rate,
        date: fx.rate_date,
        sourceId: fx.source_id,
        source: fx.source_title,
        confidence: fx.confidence,
        forDate: quotedOn,
    };
}

/** Engine Candidates from a material's price quotes; the quote itself rides along as `_row` for what the engine does not need. */
function priceCandidates(prices) {
    return prices.map((price) => ({
        _id: price.id,
        _row: price,
        value: price.amount,
        unit: price.currency,
        sourceId: price.source_id,
        confidence: price.confidence,
        validFrom: price.quoted_on,
    }));
}

/**
 * A quote in EGP by the engine's convertMoney, whatever its confidence (this is for showing, not for costing: costing
 * goes through calculate and its confidence rules). Returns { currency, egp, fx } for an EGP quote (egp = the amount itself)
 * and for a foreign one with a rate (egp = the converted amount, fx = { rate, date, source, sourceId }); or
 * { currency, egp: null, missing: { currency, date } } when there is no rate for its date.
 */
export function priceInEgp(price) {
    if (price.currency === 'EGP') {
        return { currency: 'EGP', egp: price.amount, fx: null, missing: null };
    }

    const rate = fxRateOf(price.currency, price.quoted_on, price.fx);
    const converted = rate ? convertMoney(qty(price.amount, price.currency), 'EGP', rate) : null;

    if (!converted?.ok) {
        return { currency: price.currency, egp: null, fx: null, missing: { currency: price.currency, date: price.quoted_on } };
    }

    return { currency: price.currency, egp: converted.value.amount.value.toFixed(), fx: { rate: rate.rate, date: rate.date, source: rate.source, sourceId: rate.sourceId, confidence: rate.confidence }, missing: null };
}

/**
 * An EGP amount in the display currency by the engine's convertFromEgp, with the newest rate the server sent for it
 * (`rate` as in the shared `currency` prop). Null without a rate. The figure sits next to the EGP one, never replaces it.
 */
export function toDisplayCurrency(egp, currency, rate) {
    if (egp === null || egp === undefined || egp === '' || !rate) {
        return null;
    }

    const fx = { from: currency, to: 'EGP', rate: rate.rate, date: rate.rate_date, sourceId: rate.source_id, source: rate.source_title, confidence: rate.confidence };
    const converted = convertFromEgp(qty(egp, 'EGP'), currency, fx);

    return converted.ok ? converted.value.amount.value.toFixed() : null;
}

/** The price quote the engine would cost a material with, or null when there is none (engine resolveValue). */
export function pickPrice(prices, materialId) {
    const resolved = resolveValue('price', priceCandidates(prices), {}, materialId);

    return resolved.ok && resolved.value.chosenBy === 'rule' ? resolved.value.chosen : null;
}

/**
 * Material cost per kg by the engine's calculate().
 *
 * `lines` are the editor lines; `materials` maps a material id to { prices }. A q.s. line without a share is
 * balanced first (engine balanceQs) so the cost can be previewed; the preview is never written to the line.
 *
 * Returns:
 *   state    'empty' | 'incomplete' | 'ready'
 *   perKg    decimal string, or null when the engine could not cost it
 *   perKgTraced  the engine's { value, unit, trace } behind perKg, or null
 *   missing  [{ materialId, property, reason }] straight from the engine (prices, formula problems)
 *   warnings [{ key, details[] }]
 *   prices   { [materialId]: chosen quote (engine candidate; `_row` is the server's quote with its `fx`) }
 *   equivalents { [materialId]: priceInEgp(chosen quote) } what each chosen price is in EGP, or why it cannot be
 *   usdShare the engine's traced "% of the material cost that is USD-linked", or null when the cost is not sound
 */
export function costLines(lines, materials, datasetVersion) {
    if (lines.length === 0) {
        return { state: 'empty', perKg: null, perKgTraced: null, usdShare: null, missing: [], warnings: [], prices: {}, equivalents: {} };
    }

    if (lines.some((line) => !line.material_id)) {
        return { state: 'incomplete', perKg: null, perKgTraced: null, usdShare: null, missing: [], warnings: [], prices: {}, equivalents: {} };
    }

    const lineInputs = engineLines(lines);

    const prices = {};
    const sourced = {};
    const equivalents = {};
    const rates = new Map();

    for (const line of lines) {
        const chosen = pickPrice(materials[line.material_id]?.prices ?? [], line.material_id);

        if (chosen) {
            prices[line.material_id] = chosen;
            sourced[line.material_id] = { value: chosen.value, unit: chosen.unit, sourceId: chosen.sourceId, confidence: chosen.confidence, validFrom: chosen.validFrom };
            equivalents[line.material_id] = priceInEgp(chosen._row);

            // Rates the server chose for these prices, each pinned to its quote date; the same rate of the same day appears once.
            const rate = fxRateOf(chosen.unit, chosen.validFrom, chosen._row.fx);

            if (rate) {
                rates.set(`${rate.from}|${rate.date}|${rate.sourceId}|${rate.forDate}`, rate);
            }
        }
    }

    const fxRates = [...rates.values()];
    const output = calculate({
        engineVersion: ENGINE_VERSION,
        datasetVersion,
        formula: { lines: lineInputs },
        prices: sourced,
        fxRates,
        policy: { rounding: 'default' },
    });

    // calculate() checks the formula first and stops there when it does not add up to 100. The price of every line is
    // judged by the same engine rule on its own (sourcedEgp), so a missing or estimated price shows while the formula is
    // still being typed, not only once it is complete.
    const lineChecks = lines.map((line) => sourcedEgp(line.material_id, 'price', line.material_id, sourced[line.material_id], { policy: {}, fxRates }));
    const seen = new Set();
    const missing = [...output.missing, ...lineChecks.flatMap((check) => (check.ok ? [] : check.missing))].filter((gap) => {
        const key = `${gap.materialId ?? ''}|${gap.property}|${gap.reason}`;

        return seen.has(key) ? false : seen.add(key);
    });
    const warnings = [...new Set([...output.warnings, ...lineChecks.flatMap((check) => check.warnings)])];

    // The USD-linked share of the material cost, from the engine's per-material breakdown (only when the cost itself is sound).
    const breakdown = missing.length === 0 ? productCostBreakdown({ lines: lineInputs, prices: sourced, fxRates, policy: {} }, '0') : null;
    const exposure = breakdown?.ok ? materialUsdExposure(breakdown.value) : null;

    return {
        state: 'ready',
        perKg: output.results['cost.materialPerKg']?.value ?? null,
        perKgTraced: output.results['cost.materialPerKg'] ?? null,
        usdShare: exposure?.ok ? exposure.value.usdSharePct : null,
        equivalents,
        missing,
        warnings: warnings.map((warning) => {
            const [key, ...details] = warning.split(':');

            return { key, details };
        }),
        prices,
    };
}

/** Why the engine's resolveValue left a candidate out, as a lang key. */
export const REJECT_REASON_KEYS = {
    'lower confidence': 'materials.resolve.reasons.lower_confidence',
    older: 'materials.resolve.reasons.older',
    'tie broken by sourceId': 'materials.resolve.reasons.tie_broken',
    overridden: 'materials.resolve.reasons.overridden',
    'after asOf': 'materials.resolve.reasons.after_as_of',
    'invalid candidate': 'materials.resolve.reasons.invalid',
};

/** A stored property row as the engine's resolveValue Candidate. */
const candidateOf = (row) => ({
    _id: row.id,
    value: row.value ?? undefined,
    min: row.min ?? undefined,
    max: row.max ?? undefined,
    unit: row.unit,
    sourceId: row.source_id,
    confidence: row.confidence,
    validFrom: row.valid_from,
    lotNo: row.lot_no ?? undefined,
});

/**
 * The one value the engine would use for a property of a material (resolveValue), with the row it came from, or null
 * when the material has none: { row, candidate }. Used by the calculators to prefill a sourced input.
 */
export function resolvedProperty(rows, property) {
    const group = rows.filter((row) => row.property === property);
    const result = resolveValue(property, group.map(candidateOf));

    if (!result.ok || result.value.chosenBy !== 'rule') {
        return null;
    }

    const candidate = result.value.chosen;

    return { candidate, row: group.find((row) => row.id === candidate._id) };
}

/**
 * Engine value resolution for every property of a material (resolveValue, D-04).
 * `rows` are the property rows from the server. Returns a list per property (and category):
 *   { key, property, category, resolution | null, rows: [{ row, picked, reasonKey, conflicted }] }
 * `conflicted` marks the candidates at the top confidence that disagree on the value.
 */
export function resolveProperties(rows) {
    const byProperty = new Map();

    // One value is picked per property, and per category for a property that has one (an IFRA limit of each product category).
    for (const row of rows) {
        const key = row.category ? `${row.property}|${row.category}` : row.property;
        byProperty.set(key, [...(byProperty.get(key) ?? []), row]);
    }

    return [...byProperty].map(([key, group]) => {
        const property = group[0].property;
        const candidates = group.map(candidateOf);
        const result = resolveValue(property, candidates);
        const resolution = result.ok ? result.value : null;
        const rejected = new Map((resolution?.rejected ?? []).map((entry) => [entry.candidate._id, entry]));
        const conflicting = new Set((resolution?.conflict?.candidates ?? []).map((candidate) => candidate._id));

        return {
            key,
            property,
            category: group[0].category ?? null,
            resolution,
            chosenId: resolution?.chosenBy === 'rule' ? resolution.chosen._id : null,
            rows: group.map((row) => ({
                row,
                picked: resolution?.chosenBy === 'rule' && resolution.chosen._id === row.id,
                reasonKey: REJECT_REASON_KEYS[rejected.get(row.id)?.reason] ?? null,
                conflicted: conflicting.has(row.id),
            })),
        };
    });
}
