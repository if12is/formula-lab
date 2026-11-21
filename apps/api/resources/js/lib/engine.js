/**
 * The bridge between the editor screens and the deterministic engine (@fl/engine). The browser never does
 * arithmetic on a formula or a price: every figure shown comes out of the engine (draftSummary, balanceQs,
 * calculate, resolveValue). This file only prepares what the engine takes and sorts what it gives back.
 */
import { ENGINE_VERSION, balanceQs, calculate, draftSummary, isDecimalString, resolveValue, sourcedEgp } from '@fl/engine';

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

/** Engine Candidates from a material's price quotes. */
function priceCandidates(prices) {
    return prices.map((price) => ({
        _id: price.id,
        value: price.amount,
        unit: price.currency,
        sourceId: price.source_id,
        confidence: price.confidence,
        validFrom: price.quoted_on,
    }));
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
 *   missing  [{ materialId, property, reason }] straight from the engine (prices, formula problems)
 *   warnings [{ key, details[] }]
 *   prices   { [materialId]: chosen quote }
 */
export function costLines(lines, materials, datasetVersion) {
    if (lines.length === 0) {
        return { state: 'empty', perKg: null, missing: [], warnings: [], prices: {} };
    }

    if (lines.some((line) => !line.material_id)) {
        return { state: 'incomplete', perKg: null, missing: [], warnings: [], prices: {} };
    }

    const engineLines = lines.map((line) => ({ materialId: line.material_id, percentWW: line.percent }));
    const qs = lines.find((line) => line.is_qs && line.percent === '');

    if (qs) {
        const balanced = balanceLines(lines);

        if (balanced.ok) {
            engineLines.find((line) => line.materialId === qs.material_id).percentWW = balanced.percent;
        }
    }

    const prices = {};
    const sourced = {};

    for (const line of lines) {
        const chosen = pickPrice(materials[line.material_id]?.prices ?? [], line.material_id);

        if (chosen) {
            prices[line.material_id] = chosen;
            sourced[line.material_id] = { value: chosen.value, unit: chosen.unit, sourceId: chosen.sourceId, confidence: chosen.confidence, validFrom: chosen.validFrom };
        }
    }

    const output = calculate({
        engineVersion: ENGINE_VERSION,
        datasetVersion,
        formula: { lines: engineLines },
        prices: sourced,
        policy: { rounding: 'default' },
    });

    // calculate() checks the formula first and stops there when it does not add up to 100. The price of every line is
    // judged by the same engine rule on its own (sourcedEgp), so a missing or estimated price shows while the formula is
    // still being typed, not only once it is complete.
    const lineChecks = lines.map((line) => sourcedEgp(line.material_id, 'price', line.material_id, sourced[line.material_id], { policy: {} }));
    const seen = new Set();
    const missing = [...output.missing, ...lineChecks.flatMap((check) => (check.ok ? [] : check.missing))].filter((gap) => {
        const key = `${gap.materialId ?? ''}|${gap.property}|${gap.reason}`;

        return seen.has(key) ? false : seen.add(key);
    });
    const warnings = [...new Set([...output.warnings, ...lineChecks.flatMap((check) => check.warnings)])];

    return {
        state: 'ready',
        perKg: output.results['cost.materialPerKg']?.value ?? null,
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

/**
 * Engine value resolution for every property of a material (resolveValue, D-04).
 * `rows` are the property rows from the server. Returns a list per property:
 *   { property, resolution | null, rows: [{ row, picked, reasonKey, conflicted }] }
 * `conflicted` marks the candidates at the top confidence that disagree on the value.
 */
export function resolveProperties(rows) {
    const byProperty = new Map();

    for (const row of rows) {
        byProperty.set(row.property, [...(byProperty.get(row.property) ?? []), row]);
    }

    return [...byProperty].map(([property, group]) => {
        const candidates = group.map((row) => ({
            _id: row.id,
            value: row.value ?? undefined,
            min: row.min ?? undefined,
            max: row.max ?? undefined,
            unit: row.unit,
            sourceId: row.source_id,
            confidence: row.confidence,
            validFrom: row.valid_from,
            lotNo: row.lot_no ?? undefined,
        }));
        const result = resolveValue(property, candidates);
        const resolution = result.ok ? result.value : null;
        const rejected = new Map((resolution?.rejected ?? []).map((entry) => [entry.candidate._id, entry]));
        const conflicting = new Set((resolution?.conflict?.candidates ?? []).map((candidate) => candidate._id));

        return {
            property,
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
