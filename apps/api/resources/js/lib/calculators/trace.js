/**
 * Trace nodes for results the engine returns as a bare value (HLB, mass <-> volume). The value comes from the engine
 * call; these helpers only dress it as the { value, unit, trace } the explain panel reads, with the inputs as leaves.
 * (Engine results that already carry a trace are used as they are.)
 */
export const leaf = (label, value, unit, source = null) => ({
    value,
    unit,
    trace: { op: 'input', label, value, unit, inputs: [], ...(source ? { sourceId: source.sourceId, confidence: source.confidence } : {}) },
});

export const node = (op, label, value, unit, inputs) => ({
    value,
    unit,
    trace: { op, label, value, unit, inputs: inputs.map((input) => input.trace) },
});

/** A sourced scalar (as the engine takes it) as a leaf. */
export const sourcedLeaf = (label, sourced, unit) => leaf(label, sourced.value, unit ?? sourced.unit ?? '', sourced);
