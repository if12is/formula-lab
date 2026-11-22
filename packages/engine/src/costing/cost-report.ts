/**
 * I-08: the material cost of a formula version as an auditable report. It is the SAME calculation the formula editor shows
 * (`calculate`, cost.materialPerKg), run once, so the report can never disagree with the editor; on top of it every line says
 * which quote it was costed with, which quotes lost and why, what that quote is in EGP per kg, and its share of the cost,
 * all read off the calculation trace (never recomputed here). A line with no usable price is a gap, never a zero.
 */
import { calculate } from '../calculate';
import { ENGINE_VERSION, type MissingData, type TraceNode } from '../contract';
import type { FxRate } from '../core/fx';
import { sourcedEgp } from '../cost/money';
import { resolveValue, type Candidate } from '../data/resolve';
import { balanceQs, type QsLine } from '../formula/qs';
import { dedupeMissing } from '../trace/trace';

export interface CostReportLineInput {
  materialId: string;
  /** Percent w/w as a decimal string; the q.s. line may omit it (it is balanced first). */
  percentWW?: string;
  qs?: boolean;
}

export interface CostReportInput {
  lines: readonly CostReportLineInput[];
  /** Price quotes (money per kg) per material id: the same candidates the editor hands to resolveValue. */
  quotes: Readonly<Record<string, readonly Candidate[]>>;
  fxRates?: readonly FxRate[];
  datasetVersion: string;
}

export interface CostReportLine {
  materialId: string;
  /** The share used (the balanced share for a q.s. line), decimal string. */
  percentWW: string | null;
  qs: boolean;
  /** Index into `quotes[materialId]` of the quote costed with, or null when none could be. */
  quoteIndex: number | null;
  /** Quotes that were not used, with why (resolveValue's reasons). */
  rejected: { quoteIndex: number; reason: string; detail: string }[];
  /** The chosen quote in EGP per kg (after the dated exchange rate), or null. */
  priceEgpPerKg: string | null;
  /** This line's share of the cost, EGP per kg (p/100 x price); null unless the whole cost could be computed. */
  contributionEgpPerKg: string | null;
  /** The calculation tree of the contribution, or null. */
  trace: TraceNode | null;
}

export interface CostReport {
  ok: boolean;
  lines: CostReportLine[];
  /** Σ contributions, EGP per kg, with its full calculation tree; null while any gap exists. */
  total: { value: string; unit: string; trace: TraceNode } | null;
  missing: MissingData[];
  warnings: string[];
  inputHash: string;
  outputHash: string;
  engineVersion: string;
  datasetVersion: string;
}

export function buildCostReport(input: CostReportInput): CostReport {
  const qsInputs: QsLine[] = input.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW, qs: l.qs }));
  const needsBalance = input.lines.some((l) => l.qs === true && (l.percentWW === undefined || l.percentWW === ''));
  const balanced = needsBalance ? balanceQs(qsInputs) : undefined;
  const percentOf = (l: CostReportLineInput): string | undefined => {
    if (balanced?.ok && l.qs === true) {
      return balanced.value.balanceEvent.after;
    }
    return l.percentWW === '' ? undefined : l.percentWW;
  };

  const resolved = input.lines.map((l) => {
    const candidates = input.quotes[l.materialId] ?? [];
    const r = resolveValue('price', candidates, {}, l.materialId);
    const chosen = r.ok && r.value.chosenBy === 'rule' ? r.value.chosen : undefined;
    const quoteIndex = chosen === undefined ? -1 : candidates.indexOf(chosen as Candidate);
    const rejected = r.ok ? r.value.rejected.map((x) => ({ quoteIndex: candidates.indexOf(x.candidate), reason: x.reason, detail: x.detail ?? '' })).filter((x) => x.quoteIndex >= 0) : [];
    return { line: l, chosen: chosen as Candidate | undefined, quoteIndex, rejected, percent: percentOf(l) };
  });

  const prices: Record<string, { value: string; unit: Candidate['unit']; sourceId: string; confidence: Candidate['confidence']; validFrom: string }> = {};
  for (const r of resolved) {
    if (r.chosen?.value !== undefined) {
      prices[r.line.materialId] = { value: r.chosen.value, unit: r.chosen.unit, sourceId: r.chosen.sourceId, confidence: r.chosen.confidence, validFrom: r.chosen.validFrom };
    }
  }
  const fxRates = input.fxRates ?? [];
  const output = calculate({
    engineVersion: ENGINE_VERSION,
    datasetVersion: input.datasetVersion,
    formula: { lines: resolved.map((r) => ({ materialId: r.line.materialId, percentWW: r.percent ?? '' })) },
    prices,
    fxRates: [...fxRates],
    policy: { rounding: 'default' },
  });

  // calculate() judges the formula first and stops there; each price is judged on its own too, so the gaps of every line show at once.
  const lineChecks = resolved.map((r) => sourcedEgp(r.line.materialId, 'price', r.line.materialId, prices[r.line.materialId], { policy: {}, fxRates }));
  const missing = dedupeMissing([...output.missing, ...lineChecks.flatMap((c) => (c.ok ? [] : c.missing))]);
  const warnings = [...new Set([...output.warnings, ...lineChecks.flatMap((c) => c.warnings)])];
  const total = output.results['cost.materialPerKg'];

  const lines = resolved.map((r, i): CostReportLine => {
    const term = total?.trace.inputs[i] ?? null;
    const check = lineChecks[i];
    return {
      materialId: r.line.materialId,
      percentWW: r.percent ?? null,
      qs: r.line.qs === true,
      quoteIndex: r.quoteIndex >= 0 ? r.quoteIndex : null,
      rejected: r.rejected,
      priceEgpPerKg: check?.ok ? check.value.value : null,
      contributionEgpPerKg: term?.value ?? null,
      trace: term,
    };
  });

  return {
    ok: missing.length === 0 && total !== undefined,
    lines,
    total: total === undefined ? null : { value: total.value, unit: total.unit, trace: total.trace },
    missing,
    warnings,
    inputHash: output.inputHash,
    outputHash: output.outputHash,
    engineVersion: output.engineVersion,
    datasetVersion: output.datasetVersion,
  };
}
