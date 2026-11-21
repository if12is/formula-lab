import type { Confidence, MissingData, SourcedValue, TraceNode } from '../contract';
import { dec, type Dec } from '../core/decimal';
import type { Unit } from '../core/quantity';

/**
 * Every computed value travels as { value, unit, trace } (decimal string, unit, calculation tree).
 * Units without a compound form carry the basis in the result key/label, e.g. `cost.perKg` is EGP per kg.
 */
export interface Traced {
  value: string;
  unit: Unit;
  trace: TraceNode;
}

/** Parses the traced value back to an exact Decimal. */
export function decOf(t: Traced): Dec {
  return dec(t.value);
}

function build(
  op: string,
  label: string,
  value: string | Dec,
  unit: Unit,
  inputs: readonly Traced[],
  source?: { sourceId: string; confidence?: Confidence },
): Traced {
  const text = dec(value).toFixed();
  const trace: TraceNode = { op, label, value: text, unit, inputs: inputs.map((i) => i.trace) };
  if (source !== undefined) {
    trace.sourceId = source.sourceId;
    if (source.confidence !== undefined) {
      trace.confidence = source.confidence;
    }
  }
  return { value: text, unit, trace };
}

/** A computed value with the nodes it was computed from. */
export function traced(
  op: string,
  label: string,
  value: string | Dec,
  unit: Unit,
  inputs: readonly Traced[] = [],
): Traced {
  return build(op, label, value, unit, inputs);
}

/** An unsourced input (e.g. a percentage typed into the formula, a batch size). */
export function inputLeaf(label: string, value: string | Dec, unit: Unit): Traced {
  return build('input', label, value, unit, []);
}

/** A leaf backed by a SourcedValue: keeps sourceId and confidence so the UI can show provenance. */
export function sourcedLeaf(label: string, sv: SourcedValue): Traced {
  return build('input', label, sv.value, sv.unit, [], { sourceId: sv.sourceId, confidence: sv.confidence });
}

/** A leaf with a source and, when it has one, a confidence level (e.g. an exchange rate). */
export function referenceLeaf(label: string, value: string | Dec, unit: Unit, sourceId: string, confidence?: Confidence): Traced {
  return build('input', label, value, unit, [], { sourceId, confidence });
}

/** Everything a calculation step produced: keyed results, every gap, every warning. */
export interface Outcome {
  results: Record<string, Traced>;
  missing: MissingData[];
  warnings: string[];
}

export function emptyOutcome(): Outcome {
  return { results: {}, missing: [], warnings: [] };
}

/** Removes repeated gaps (same material + property + reason), keeping first-seen order. */
export function dedupeMissing(items: readonly MissingData[]): MissingData[] {
  const seen = new Set<string>();
  const out: MissingData[] = [];
  for (const item of items) {
    const key = `${item.materialId ?? ''}\u0000${item.property}\u0000${item.reason}`;
    if (!seen.has(key)) {
      seen.add(key);
      out.push(item);
    }
  }
  return out;
}

export function mergeOutcomes(...parts: readonly Outcome[]): Outcome {
  return {
    results: Object.assign({}, ...parts.map((p) => p.results)) as Record<string, Traced>,
    missing: dedupeMissing(parts.flatMap((p) => p.missing)),
    warnings: parts.flatMap((p) => p.warnings),
  };
}

/** Walks a trace tree depth-first and returns every leaf (nodes without inputs). */
export function leavesOf(node: TraceNode): TraceNode[] {
  return node.inputs.length === 0 ? [node] : node.inputs.flatMap(leavesOf);
}
