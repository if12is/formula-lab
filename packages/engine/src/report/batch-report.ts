import { type Confidence, type EngineInput, type EngineOutput, type MissingData, type TraceNode } from '../contract';
import { dec } from '../core/decimal';
import { Quantity, currencyOf, type Unit } from '../core/quantity';
import { missing, ok, type Result } from '../core/result';
import { roundMass, roundMoney, roundPercent, roundToStep, roundVolume, roundingResidual, DISPLAY_STEP, type RoundingResidual } from '../core/round';
import { canonicalJson, inputHash, outputHash, sha256Hex } from '../hash';
import { leavesOf } from '../trace/trace';

export const BATCH_REPORT_SCHEMA = 'fl.batch-report/1';

export interface BatchReportMeta {
  formulaCode: string;
  formulaVersion: string;
  /** Hash of the stored formula version (the caller's record), tied to the report. */
  formulaVersionHash: string;
  batchRef: string;
}

export interface BuildBatchReportOptions {
  /** Allow a report for an output with gaps; the document is then flagged `draft: true`. */
  allowDraft?: boolean;
}

export interface ReportHeader extends BatchReportMeta {
  engineVersion: string;
  datasetVersion: string;
  inputHash: string;
  outputHash: string;
}

export interface ReportLine {
  materialId: string;
  /** Exact, as entered. */
  percentWW: string;
  percentDisplay: string;
  /** Exact scaled mass in grams (percent x gross batch mass); null when the gross mass is not available (draft). */
  massG: string | null;
  massDisplay: string | null;
}

export interface ReportFormulaBlock {
  lines: ReportLine[];
  percentSum: string;
  percentSumDisplay: string;
  /** Display residual of the percent column versus its total. */
  percentRounding: RoundingResidual;
  /** Weighed total = gross batch mass in grams, null when unavailable. */
  totalMassG: string | null;
  totalMassDisplay: string | null;
  /** Rounded line masses vs rounded total; `hasResidual` flags a display that does not add up. Nothing is redistributed. */
  massRounding: RoundingResidual | null;
}

export interface ReportValue {
  key: string;
  /** Exact decimal string. */
  value: string;
  unit: Unit;
  display: string;
  displayUnit: string;
}

export interface ReportSource {
  sourceId: string;
  /** Distinct confidence levels this source carried in the traces (empty for reference-only leaves). */
  confidences: Confidence[];
  /** Distinct labels of the trace leaves that cite the source. */
  labels: string[];
}

export interface BatchReport {
  schema: typeof BATCH_REPORT_SCHEMA;
  /** true when built from an output with gaps; part of the hash. */
  draft: boolean;
  header: ReportHeader;
  formula: ReportFormulaBlock;
  cost: ReportValue[];
  batch: ReportValue[];
  /** Message keys, never prose. */
  missing: MissingData[];
  warnings: string[];
  sources: ReportSource[];
  /** SHA-256 of the canonical JSON of this document without this field. */
  reportHash: string;
}

/**
 * Issue stamp. Lives OUTSIDE the hashed body on purpose: the engine is deterministic and the report
 * hash must be reproducible, so the caller adds the time and the person after building the report.
 * Reissuing the same report at another time keeps the same `reportHash`.
 */
export interface BatchReportEnvelope {
  report: BatchReport;
  /** ISO timestamp set by the caller, not hashed. */
  issuedAt: string;
  issuedBy: string;
}

type ReportBody = Omit<BatchReport, 'reportHash'>;

function normaliseSizes(input: EngineInput): EngineInput {
  if (input.batch === undefined) {
    return input;
  }
  const q = (v: Quantity | { value: string; unit: Unit }): { value: string; unit: Unit } => (v instanceof Quantity ? v.toJSON() : v);
  return { ...input, batch: { ...input.batch, size: q(input.batch.size), packSize: q(input.batch.packSize) } };
}

function gap(property: string, reason: string): MissingData {
  return { property, reason };
}

/** Everything that must hold for the (input, output, meta) triple to be reportable. */
function preconditions(input: EngineInput, output: EngineOutput, meta: BatchReportMeta, allowDraft: boolean): MissingData[] {
  const gaps: MissingData[] = [];
  for (const [key, value] of Object.entries(meta)) {
    if (value.trim() === '') {
      gaps.push(gap(`meta.${key}`, 'report.meta_required'));
    }
  }
  if (outputHash(output) !== output.outputHash) {
    gaps.push(gap('output', 'report.output_hash_mismatch'));
  }
  if (inputHash(normaliseSizes(input)) !== output.inputHash) {
    gaps.push(gap('input', 'report.input_output_mismatch'));
  }
  if (!output.ok && !allowDraft) {
    gaps.push(gap('output', 'report.output_not_ok'), ...output.missing);
  }
  return gaps;
}

function display(key: string, value: string, unit: Unit): ReportValue {
  const exact = { key, value, unit };
  if (currencyOf(unit) !== undefined) {
    return { ...exact, display: roundMoney(new Quantity(value, unit)), displayUnit: unit };
  }
  switch (unit) {
    case 'kg':
    case 'g':
    case 'mg':
      return { ...exact, display: roundMass(new Quantity(value, unit)), displayUnit: 'g' };
    case 'L':
    case 'mL':
      return { ...exact, display: roundVolume(new Quantity(value, unit)), displayUnit: 'mL' };
    case 'pc':
      return { ...exact, display: roundToStep(value, '1'), displayUnit: 'pc' };
    default:
      return { ...exact, display: roundToStep(value, DISPLAY_STEP.percent), displayUnit: unit };
  }
}

function valuesWithPrefix(output: EngineOutput, prefix: string): ReportValue[] {
  return Object.keys(output.results)
    .filter((k) => k.startsWith(prefix))
    .sort()
    .map((k) => {
      const r = output.results[k];
      return display(k, r?.value ?? '', r?.unit ?? 'pc');
    });
}

function formulaBlock(input: EngineInput, output: EngineOutput): ReportFormulaBlock {
  const grossKg = output.results['batch.grossMass'];
  const grossG = grossKg === undefined ? undefined : new Quantity(grossKg.value, grossKg.unit).to('g').value;
  const lines: ReportLine[] = input.formula.lines.map((l) => {
    const massG = grossG === undefined ? undefined : grossG.mul(dec(l.percentWW)).div(100);
    return {
      materialId: l.materialId,
      percentWW: l.percentWW,
      percentDisplay: roundPercent(l.percentWW),
      massG: massG === undefined ? null : massG.toFixed(),
      massDisplay: massG === undefined ? null : roundMass(new Quantity(massG, 'g')),
    };
  });
  const percentSum = input.formula.lines.reduce((acc, l) => acc.plus(dec(l.percentWW)), dec('0'));
  const massRounding =
    grossG === undefined
      ? null
      : roundingResidual(lines.map((l) => l.massG ?? '0'), grossG, DISPLAY_STEP.massG);
  return {
    lines,
    percentSum: percentSum.toFixed(),
    percentSumDisplay: roundPercent(percentSum),
    percentRounding: roundingResidual(input.formula.lines.map((l) => l.percentWW), percentSum, DISPLAY_STEP.percent),
    totalMassG: grossG === undefined ? null : grossG.toFixed(),
    totalMassDisplay: grossG === undefined ? null : roundMass(new Quantity(grossG, 'g')),
    massRounding,
  };
}

/** Every distinct sourceId in every trace leaf, with the confidences and labels seen. Sorted. */
function collectSources(output: EngineOutput): ReportSource[] {
  const bySource = new Map<string, { confidences: Set<Confidence>; labels: Set<string> }>();
  const roots: TraceNode[] = Object.keys(output.results).sort().flatMap((k) => (output.results[k] ? [output.results[k].trace] : []));
  for (const leaf of roots.flatMap(leavesOf)) {
    if (leaf.sourceId === undefined) {
      continue;
    }
    const entry = bySource.get(leaf.sourceId) ?? { confidences: new Set<Confidence>(), labels: new Set<string>() };
    if (leaf.confidence !== undefined) {
      entry.confidences.add(leaf.confidence);
    }
    entry.labels.add(leaf.label);
    bySource.set(leaf.sourceId, entry);
  }
  return [...bySource.keys()].sort().map((sourceId) => {
    const e = bySource.get(sourceId);
    return { sourceId, confidences: [...(e?.confidences ?? [])].sort(), labels: [...(e?.labels ?? [])].sort() };
  });
}

function bodyOf(report: BatchReport): ReportBody {
  const rest: Partial<BatchReport> = { ...report };
  delete rest.reportHash;
  return rest as ReportBody;
}

/** SHA-256 over the canonical JSON of the report without `reportHash`. */
export function computeReportHash(report: BatchReport | ReportBody): string {
  return sha256Hex(canonicalJson(bodyOf(report as BatchReport)));
}

/**
 * Builds the signed batch report document (a pure document: the PDF is rendered from it later).
 * Refuses (Result missing) when `output.ok` is false unless `allowDraft`, and when the output does not
 * belong to the input or its own hash does not verify. No timestamps inside: see BatchReportEnvelope.
 * The `draft` flag, the formula version hash, engine/data versions and every displayed value are hashed.
 */
export function buildBatchReport(
  input: EngineInput,
  output: EngineOutput,
  meta: BatchReportMeta,
  options: BuildBatchReportOptions = {},
): Result<BatchReport> {
  const gaps = preconditions(input, output, meta, options.allowDraft === true);
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const body: ReportBody = {
    schema: BATCH_REPORT_SCHEMA,
    draft: !output.ok,
    header: {
      formulaCode: meta.formulaCode,
      formulaVersion: meta.formulaVersion,
      formulaVersionHash: meta.formulaVersionHash,
      batchRef: meta.batchRef,
      engineVersion: output.engineVersion,
      datasetVersion: output.datasetVersion,
      inputHash: output.inputHash,
      outputHash: output.outputHash,
    },
    formula: formulaBlock(input, output),
    cost: [...valuesWithPrefix(output, 'cost.'), ...valuesWithPrefix(output, 'price.')],
    batch: valuesWithPrefix(output, 'batch.'),
    missing: output.missing,
    warnings: output.warnings,
    sources: collectSources(output),
  };
  return ok({ ...body, reportHash: computeReportHash(body) });
}

/** True iff `reportHash` matches the document body (detects any edit after signing). */
export function verifyBatchReport(report: BatchReport): boolean {
  return report.reportHash === computeReportHash(report);
}
