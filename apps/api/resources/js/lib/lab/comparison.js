/**
 * Expected against measured for one trial (O-05), through the engine: the expected active matter is activeMatterOfBlend over
 * the version's lines and each material's registered active matter (the value resolveValue would use), the measured one is the
 * trial's reading, and compareExpectedMeasured gives the absolute and the relative difference. There is no tolerance and no
 * verdict anywhere here. Missing either side is the engine's own `missing`, listed in full.
 */
import { activeMatterOfBlend, compareExpectedMeasured, dec, isDecimalString, qty } from '@fl/engine';
import { balanceLines, resolvedProperty } from '../engine';

const ACTIVE_UNIT = '%w/w';

/** The registered value the engine would use for a material, as the SourcedScalar activeMatterOfBlend takes, or undefined. */
function registered(line, property) {
    const found = resolvedProperty(line.properties, property);

    if (!found || found.candidate.value === undefined) {
        return undefined;
    }

    const { candidate } = found;

    return { value: candidate.value, unit: ACTIVE_UNIT, sourceId: candidate.sourceId, confidence: candidate.confidence, validFrom: candidate.validFrom };
}

/** The shares of the lines as plain percentages: a q.s. line is balanced by the engine (balanceQs), never by the browser. */
function sharesOf(lines) {
    const inputs = lines.map((line) => ({ key: line.key, material_id: line.material_id, percent: line.percent ?? '', is_qs: line.is_qs }));
    const qs = inputs.find((line) => line.is_qs && line.percent === '');

    if (!qs) {
        return { ok: true, percents: inputs.map((line) => line.percent) };
    }

    const balanced = balanceLines(inputs);

    return balanced.ok
        ? { ok: true, percents: inputs.map((line) => (line === qs ? balanced.percent : line.percent)) }
        : { ok: false, missing: balanced.errors.map((error) => ({ property: 'dose', reason: error.messageKey })) };
}

/** The engine's expected active matter of the trial's version: a Result { ok, value: { activeMatterPct, ... } } or missing. */
export function expectedActive(lines, property) {
    const shares = sharesOf(lines);

    if (!shares.ok) {
        return { ok: false, missing: shares.missing, warnings: [] };
    }

    return activeMatterOfBlend({
        policy: {},
        lines: lines.map((line, index) => {
            const activeMatter = registered(line, property);

            return {
                materialId: line.label,
                mass: qty(isDecimalString(shares.percents[index]) ? shares.percents[index] : '0', 'g'),
                // A material with no registered value is asked for one (active.value_required); a registered zero declares "no active matter".
                contributesActive: activeMatter === undefined || dec(activeMatter.value).gt(0),
                activeMatter,
            };
        }),
    });
}

const warningOf = (raw) => {
    const [key, ...details] = String(raw).split(':');

    return { key, details, raw: String(raw) };
};

/**
 * The comparison outcome the panel shows: { state: 'missing' | 'ready', rows, missing, warnings }.
 * `comparison` is App\Services\Lab\LabComparisonService::forTrial().
 */
export function runComparison(comparison) {
    const expected = expectedActive(comparison.lines, comparison.property);
    const measured = comparison.measured
        ? { value: comparison.measured.value, unit: comparison.measured.unit, sourceId: comparison.measured.sourceId, confidence: comparison.measured.confidence, validFrom: comparison.measured.validFrom }
        : undefined;
    const result = compareExpectedMeasured(comparison.property, expected.ok ? expected.value.activeMatterPct : undefined, measured);
    const warnings = [...expected.warnings, ...result.warnings].map(warningOf);

    if (!result.ok) {
        // The engine's detailed reasons for the expected side replace its generic "no expected value".
        const specific = expected.ok ? [] : expected.missing;
        const missing = [...specific, ...result.missing.filter((gap) => !(specific.length && gap.reason === 'compare.expected_missing'))];

        return { state: 'missing', rows: [], missing, warnings };
    }

    const { expected: expectedTraced, measured: measuredTraced, difference, relativePct } = result.value;

    return {
        state: 'ready',
        rows: [
            { key: 'expected', traced: expectedTraced, decimals: 2 },
            { key: 'measured', traced: measuredTraced, decimals: 2 },
            { key: 'difference', traced: difference, decimals: 2, primary: true },
            ...(relativePct ? [{ key: 'relative', traced: relativePct, decimals: 2, primary: true }] : []),
        ],
        missing: [],
        warnings,
    };
}
