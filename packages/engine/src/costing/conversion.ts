/**
 * I-02: conversion cost per unit (plan/00-research/06-cost-drivers.md §5).
 *
 *   labour / energy / water line = rate × quantity           (quantity per unit, or per batch ÷ units per batch)
 *   overhead = pool per period ÷ allocation-key total × key quantity of one unit
 *
 * Rates are dated sourced EGP values (EGP per minute, per kWh, per L of water); the basis is in the line id and
 * label. An overhead is allocated by a FIXED key chosen once and recorded on the line: `per_kg` (the unit's
 * mass in kg against the kg produced in the period) or `per_line_hour` (line hours of one unit against line
 * hours of the period). Nothing is defaulted: a missing rate, quantity or key total is a gap.
 */
import type { SourcedValue } from '../contract';
import type { SourcedScalar } from '../core/parse';
import type { ResolvedNumber } from '../sourced-input';
import { all, missing, ok, type Result } from '../core/result';
import { sourcedEgp, type MoneyContext } from '../cost/money';
import { decOf, dedupeMissing, traced, type Traced } from '../trace/trace';
import { duplicateIds, gapsOf, lineCurrency, resolveScalar, sumTraced, type CostLine } from './line';

export type ConversionKind = 'labour' | 'energy' | 'water' | 'other';
export type AllocationKey = 'per_kg' | 'per_line_hour';

export interface ConversionLineInput {
  id: string;
  kind: ConversionKind;
  /** Dated rate: EGP per minute (labour, loaded), per kWh (energy), per L (water). */
  rate?: SourcedValue;
  /** Quantity consumed per batch; needs `unitsPerBatch`. Give this or `qtyPerUnit`, not both. */
  qtyPerBatch?: SourcedScalar;
  /** Quantity consumed per selling unit. */
  qtyPerUnit?: SourcedScalar;
}

export interface OverheadInput {
  id: string;
  /** Pool of the period (rent, depreciation, management), dated, EGP. */
  periodCost?: SourcedValue;
  /** The fixed allocation key. */
  key?: AllocationKey;
  /** Total of the key in the period: kg produced (`per_kg`) or line hours (`per_line_hour`). */
  keyTotal?: SourcedScalar;
  /** Line hours one unit takes; needed for `per_line_hour`. */
  lineHoursPerUnit?: SourcedScalar;
}

export interface ConversionInput extends MoneyContext {
  /** Selling units per batch, needed by any `qtyPerBatch` line. */
  unitsPerBatch?: SourcedScalar;
  /** Mass of one selling unit in kg, needed by any `per_kg` overhead (the fill, from the unit cost). */
  unitMassKg?: Traced;
  lines?: readonly ConversionLineInput[];
  overheads?: readonly OverheadInput[];
}

export interface ConversionCost {
  lines: CostLine[];
  /** EGP per selling unit. */
  total: Traced;
}

export const CONVERSION_REASONS = {
  DUPLICATE: 'conversion.duplicate_id',
  QTY_BASIS: 'conversion.give_exactly_one_of_qty_per_batch_or_per_unit',
  INVALID_KEY: 'conversion.invalid_allocation_key',
  SECTION_MISSING: 'conversion.section_missing',
} as const;

function conversionLine(line: ConversionLineInput, input: ConversionInput): Result<CostLine> {
  const id = line.id;
  const rate = sourcedEgp(id, 'rate', `${id} rate (EGP per ${line.kind} basis unit)`, line.rate, input);
  const hasBatch = line.qtyPerBatch !== undefined;
  const hasUnit = line.qtyPerUnit !== undefined;
  if (hasBatch === hasUnit) {
    return missing([...gapsOf(rate), { materialId: id, property: 'qty', reason: CONVERSION_REASONS.QTY_BASIS }], rate.warnings);
  }
  if (hasUnit) {
    const qty = resolveScalar(id, 'qtyPerUnit', `${id} qty per unit`, line.qtyPerUnit, 'pc', input);
    const parts = all([rate, qty] as const);
    if (!parts.ok) {
      return parts;
    }
    const [r, q] = parts.value;
    const perUnit = traced('mul', `${id} per unit = rate × qty`, decOf(r).mul(q.value), 'EGP', [r, q.leaf]);
    return ok({ key: `conversion:${id}`, label: id, perUnit, currency: lineCurrency(line.rate as SourcedValue), kind: line.kind }, parts.warnings);
  }
  const qty = resolveScalar(id, 'qtyPerBatch', `${id} qty per batch`, line.qtyPerBatch, 'pc', input);
  const batch = resolveScalar(undefined, 'unitsPerBatch', 'Selling units per batch', input.unitsPerBatch, 'pc', input, { minExclusive: true });
  const parts = all([rate, qty, batch] as const);
  if (!parts.ok) {
    return parts;
  }
  const [r, q, b] = parts.value;
  const perBatch = traced('mul', `${id} per batch = rate × qty`, decOf(r).mul(q.value), 'EGP', [r, q.leaf]);
  const perUnit = traced('div', `${id} per unit = per batch ÷ units per batch`, decOf(perBatch).div(b.value), 'EGP', [perBatch, b.leaf]);
  return ok({ key: `conversion:${id}`, label: id, perUnit, currency: lineCurrency(line.rate as SourcedValue), kind: line.kind }, parts.warnings);
}

function overheadLine(oh: OverheadInput, input: ConversionInput): Result<CostLine> {
  const id = oh.id;
  const pool = sourcedEgp(id, 'periodCost', `${id} pool per period (EGP)`, oh.periodCost, input);
  if (oh.key !== 'per_kg' && oh.key !== 'per_line_hour') {
    return missing([...gapsOf(pool), { materialId: id, property: 'key', reason: oh.key === undefined ? 'input.missing' : CONVERSION_REASONS.INVALID_KEY }], pool.warnings);
  }
  const perKg = oh.key === 'per_kg';
  const total = resolveScalar(id, 'keyTotal', `${id} key total (${perKg ? 'kg' : 'line hours'} per period)`, oh.keyTotal, perKg ? 'kg' : 'pc', input, { minExclusive: true });
  const share: Result<ResolvedNumber> = perKg
    ? input.unitMassKg === undefined
      ? missing([{ materialId: id, property: 'unitMassKg', reason: 'input.missing' }])
      : ok({ value: decOf(input.unitMassKg), leaf: input.unitMassKg })
    : resolveScalar(id, 'lineHoursPerUnit', `${id} line hours per unit`, oh.lineHoursPerUnit, 'pc', input);
  const parts = all([pool, total, share] as const);
  if (!parts.ok) {
    return parts;
  }
  const [p, t, s] = parts.value;
  const rate = traced('div', `${id} rate = pool ÷ ${oh.key} total`, decOf(p).div(t.value), 'EGP', [p, t.leaf]);
  const perUnit = traced('mul', `${id} per unit = rate × ${oh.key} of one unit`, decOf(rate).mul(s.value), 'EGP', [rate, s.leaf]);
  return ok({ key: `conversion:${id}`, label: `${id} (${oh.key})`, perUnit, currency: lineCurrency(oh.periodCost as SourcedValue), kind: 'overhead' }, parts.warnings);
}

/** Conversion cost per selling unit; every gap of every line is listed. */
export function conversionCostPerUnit(input: ConversionInput): Result<ConversionCost> {
  if (input.lines === undefined || input.overheads === undefined) {
    return missing([
      ...(input.lines === undefined ? [{ property: 'conversion.lines', reason: CONVERSION_REASONS.SECTION_MISSING }] : []),
      ...(input.overheads === undefined ? [{ property: 'conversion.overheads', reason: CONVERSION_REASONS.SECTION_MISSING }] : []),
    ]);
  }
  const dups = duplicateIds([...input.lines.map((l) => l.id), ...input.overheads.map((o) => o.id)], 'id', CONVERSION_REASONS.DUPLICATE);
  const results = [...input.lines.map((l) => conversionLine(l, input)), ...input.overheads.map((o) => overheadLine(o, input))];
  const warnings = results.flatMap((r) => r.warnings);
  const gaps = dedupeMissing([...dups, ...results.flatMap((r) => (r.ok ? [] : r.missing))]);
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  const lines = results.map((r) => (r as { ok: true; value: CostLine }).value);
  return ok({ lines, total: sumTraced('Conversion cost per unit = Σ lines', lines.map((l) => l.perUnit)) }, warnings);
}
