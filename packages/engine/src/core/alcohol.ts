/**
 * OIML R 22 (1975) alcoholometric lookups at 20 C: density <-> %w/w <-> %v/v.
 *
 * data/oiml-r22/table-20c.json is generated (scripts/gen-oiml-r22.ts) from the official
 * polynomial rho20(p) = A1 + sum A_k p^(k-1) (OIML R 22 p.4-5, p.12-13) with %v/v from
 * q = p * rho20(p) / rho20(100%) (p.5, table IIIb), on a 0.1 %w/w grid. This module
 * interpolates linearly between rows and never extrapolates. If the table has no rows,
 * every function returns `missing: oiml_r22_table`.
 */
import tableJson from '../../data/oiml-r22/table-20c.json';
import { D, dec, type Dec } from './decimal';
import { missing, ok, type Result } from './result';

export const SOURCE = {
  document: 'OIML R 22 (1975), International alcoholometric tables',
  alsoReproducedIn: 'Council Directive 76/766/EEC, Annex',
  url: 'https://www.oiml.org/en/files/pdf_r/r022-e75.pdf',
  retrievedOn: '2026-11-21',
  sections: 'p.4-5 (method), p.12-13 (formula, coefficients), p.44-45 (Table IIIa), p.48-49 (Table IIIb)',
  confidence: 'L3_standard',
  temperatureC: 20,
} as const;

export interface OimlRow {
  pctVV: string;
  pctWW: string;
  densityKgM3: string;
}
export interface OimlTable {
  rows: readonly OimlRow[];
}

const DEFAULT_TABLE: OimlTable = tableJson as OimlTable;

type Col = 'pctVV' | 'pctWW' | 'densityKgM3';

/**
 * Linear interpolation of column `to` over column `from`. The table's own range is the
 * valid range (0..100 for the shipped table); inputs outside it are never extrapolated.
 * Inputs must be decimal strings or Decimals (JS numbers are rejected by dec()).
 */
function interpolate(table: OimlTable, from: Col, to: Col, inputName: string, raw: unknown): Result<Dec> {
  let x: Dec;
  try {
    x = dec(raw as string);
  } catch {
    return missing([{ property: inputName, reason: 'invalid_input: expected decimal string or Decimal (JS numbers rejected)' }]);
  }
  if (table.rows.length < 2) {
    return missing([{ property: 'oiml_r22_table', reason: 'table_empty_or_not_loaded' }]);
  }
  const pts = table.rows
    .map((r) => ({ x: new D(r[from]), y: new D(r[to]) }))
    .sort((a, b) => a.x.comparedTo(b.x));
  const lowest = pts[0]!.x;
  const highest = pts[pts.length - 1]!.x;
  if (x.lt(lowest) || x.gt(highest)) {
    return missing([
      {
        property: inputName,
        reason: `out_of_range: OIML R 22 table covers ${lowest.toString()}..${highest.toString()}`,
      },
    ]);
  }
  // First point with x >= input; it exists because input <= highest. If it is index 0 it equals input.
  const i = pts.findIndex((p) => p.x.gte(x));
  const upper = pts[i]!;
  if (upper.x.eq(x)) {
    return ok(upper.y);
  }
  const lower = pts[i - 1]!;
  const t = x.minus(lower.x).div(upper.x.minus(lower.x));
  return ok(lower.y.plus(upper.y.minus(lower.y).times(t)));
}

export function densityFromWW(pctWW: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'pctWW', 'densityKgM3', 'pctWW', pctWW);
}
export function wwFromDensity(density: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'densityKgM3', 'pctWW', 'densityKgM3', density);
}
export function vvFromWW(pctWW: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'pctWW', 'pctVV', 'pctWW', pctWW);
}
export function wwFromVV(pctVV: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'pctVV', 'pctWW', 'pctVV', pctVV);
}
export function densityFromVV(pctVV: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'pctVV', 'densityKgM3', 'pctVV', pctVV);
}
export function vvFromDensity(density: unknown, table: OimlTable = DEFAULT_TABLE): Result<Dec> {
  return interpolate(table, 'densityKgM3', 'pctVV', 'densityKgM3', density);
}
