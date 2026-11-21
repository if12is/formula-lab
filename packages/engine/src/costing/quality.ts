/**
 * I-03: quality and regulatory cost per unit (plan/00-research/06-cost-drivers.md §6).
 *
 *   batch tests    = test price × tests per batch ÷ units per batch
 *   regulatory     = fee ÷ expected quantity over the notification period (EDA fee, IFRA certificate, registration)
 *
 * All prices and quantities are dated/sourced inputs. Regulatory fees reuse the one-time amortization.
 */
import type { SourcedValue } from '../contract';
import type { SourcedScalar } from '../core/parse';
import { all, missing, ok, type Result } from '../core/result';
import { sourcedEgp, type MoneyContext } from '../cost/money';
import { amortizeOneTimeCosts, type OneTimeCostInput } from '../packaging/one-time';
import { decOf, dedupeMissing, traced, type Traced } from '../trace/trace';
import { duplicateIds, lineCurrency, resolveScalar, sumTraced, type CostLine } from './line';

export interface BatchTestInput {
  id: string;
  /** Price of one test run (pH, density, microbiology ...), dated and sourced. */
  costPerTest?: SourcedValue;
  testsPerBatch?: SourcedScalar;
}

export interface QualityInput extends MoneyContext {
  /** Selling units per batch, needed when any batch test is listed. */
  unitsPerBatch?: SourcedScalar;
  batchTests?: readonly BatchTestInput[];
  /** EDA fees, IFRA certificates ...: amount ÷ expected quantity over the validity period. */
  regulatory?: readonly OneTimeCostInput[];
}

export interface QualityCost {
  /** Batch-test lines (`quality:<id>`) followed by regulatory lines (`regulatory:<id>`). */
  lines: CostLine[];
  tests: Traced;
  regulatory: Traced;
  /** EGP per selling unit. */
  total: Traced;
}

export const QUALITY_REASONS = {
  DUPLICATE: 'quality.duplicate_test_id',
  SECTION_MISSING: 'quality.section_missing',
} as const;

function testLine(test: BatchTestInput, input: QualityInput): Result<CostLine> {
  const id = test.id;
  const parts = all([
    sourcedEgp(id, 'costPerTest', `${id} price per test (EGP)`, test.costPerTest, input),
    resolveScalar(id, 'testsPerBatch', `${id} tests per batch`, test.testsPerBatch, 'pc', input),
    resolveScalar(undefined, 'unitsPerBatch', 'Selling units per batch', input.unitsPerBatch, 'pc', input, { minExclusive: true }),
  ] as const);
  if (!parts.ok) {
    return parts;
  }
  const [price, tests, batch] = parts.value;
  const perBatch = traced('mul', `${id} per batch = price × tests`, decOf(price).mul(tests.value), 'EGP', [price, tests.leaf]);
  const perUnit = traced('div', `${id} per unit = per batch ÷ units per batch`, decOf(perBatch).div(batch.value), 'EGP', [perBatch, batch.leaf]);
  return ok({ key: `quality:${id}`, label: id, perUnit, currency: lineCurrency(test.costPerTest as SourcedValue), kind: 'batch_test' }, parts.warnings);
}

/** Quality and regulatory cost per selling unit; every gap listed. */
export function qualityCostPerUnit(input: QualityInput): Result<QualityCost> {
  if (input.batchTests === undefined || input.regulatory === undefined) {
    return missing([
      ...(input.batchTests === undefined ? [{ property: 'quality.batchTests', reason: QUALITY_REASONS.SECTION_MISSING }] : []),
      ...(input.regulatory === undefined ? [{ property: 'quality.regulatory', reason: QUALITY_REASONS.SECTION_MISSING }] : []),
    ]);
  }
  const dups = duplicateIds(input.batchTests.map((t) => t.id), 'id', QUALITY_REASONS.DUPLICATE);
  const tests = input.batchTests.map((t) => testLine(t, input));
  const regulatory = amortizeOneTimeCosts(input.regulatory, input, 'regulatory', 'Regulatory cost per unit = Σ fee ÷ expected quantity');
  const warnings = [...tests.flatMap((t) => t.warnings), ...regulatory.warnings];
  const gaps = dedupeMissing([...dups, ...tests.flatMap((t) => (t.ok ? [] : t.missing)), ...(regulatory.ok ? [] : regulatory.missing)]);
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  const testLines = tests.map((t) => (t as { ok: true; value: CostLine }).value);
  const reg = (regulatory as { ok: true; value: { lines: CostLine[]; total: Traced } }).value;
  const testsTotal = sumTraced('Batch tests per unit = Σ tests', testLines.map((l) => l.perUnit));
  const total = sumTraced('Quality & regulatory per unit = tests + regulatory', [testsTotal, reg.total]);
  return ok({ lines: [...testLines, ...reg.lines], tests: testsTotal, regulatory: reg.total, total }, warnings);
}
