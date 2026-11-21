/**
 * "Tested on formula X" (O-07): when a formula version's stability test passed in the lab (the caller decides that: a
 * completed trial with a stability result on an approved version), the dose of every line and the required HLB of each
 * material that were used in that version become PROVENANCE records: "this value was tested in this formula". They are
 * local lab history (L1), never a reference number: nothing here feeds a calculation, sets a limit or changes a value.
 *
 *   testedValuesOf  the values a version used (balanced q.s. share as the dose; the rHLB the engine's resolveValue picks)
 *   testedOnTags    which of the values a screen is using carry such a record: same material, same kind, SAME number
 *
 * A changed value is a different value: it is not tagged. Pure, decimal.js only.
 */
import type { Confidence, MissingData } from '../contract';
import { parseDec } from '../core/parse';
import { missing, ok, type Result } from '../core/result';
import { resolveValue, type Candidate } from '../data/resolve';
import { balanceQs, type QsLine } from '../formula/qs';

export type TestedKind = 'dose' | 'required_hlb';

export const TESTED_CONFIDENCE: Confidence = 'L1_coa_batch';

export interface TestedLine {
  materialId: string;
  /** % w/w; empty/absent only for the q.s. line, which the engine balances. */
  percentWW?: string;
  qs?: boolean;
}

/** A registered required-HLB value of a material (one candidate for resolveValue). */
export interface RhlbCandidate {
  value: string;
  sourceId: string;
  confidence: Confidence;
  /** ISO date. */
  validFrom: string;
  lotNo?: string;
}

export interface TestedValue {
  kind: TestedKind;
  materialId: string;
  /** Decimal string: % w/w for a dose, HLB for a required HLB. */
  value: string;
  /** The registered value's source (required HLB only; a dose comes from the formula itself). */
  sourceId?: string;
}

/**
 * The dose and required HLB of every line of a version. A q.s. line is balanced by the engine first. A material with no
 * required HLB simply has no such record; a formula the engine cannot balance gives `missing`.
 */
export function testedValuesOf(lines: readonly TestedLine[], rhlb: Readonly<Record<string, readonly RhlbCandidate[]>>): Result<TestedValue[]> {
  const needsBalance = lines.some((l) => l.qs === true && (l.percentWW === undefined || l.percentWW === ''));
  let doses: { materialId: string; percentWW: string }[];
  if (needsBalance) {
    const balanced = balanceQs(lines.map((l): QsLine => ({ materialId: l.materialId, percentWW: l.percentWW === '' ? undefined : l.percentWW, qs: l.qs })));
    if (!balanced.ok) {
      return missing(balanced.errors.map((e): MissingData => ({ materialId: e.materialId, property: 'dose', reason: e.messageKey })));
    }
    doses = balanced.value.lines;
  } else {
    const bad = lines.filter((l) => parseDec(l.percentWW) === null);
    if (bad.length > 0) {
      return missing(bad.map((l): MissingData => ({ materialId: l.materialId, property: 'dose', reason: 'formula.percent_invalid' })));
    }
    doses = lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW as string }));
  }
  const out: TestedValue[] = [];
  for (const d of doses) {
    out.push({ kind: 'dose', materialId: d.materialId, value: d.percentWW });
    const candidates: Candidate[] = (rhlb[d.materialId] ?? []).map((c) => ({
      value: c.value,
      // HLB is dimensionless: resolveValue only compares units for agreement, so a placeholder count unit is used.
      unit: 'pc',
      sourceId: c.sourceId,
      confidence: c.confidence,
      validFrom: c.validFrom,
      ...(c.lotNo !== undefined && { lotNo: c.lotNo }),
    }));
    if (candidates.length === 0) {
      continue;
    }
    const resolved = resolveValue('required_hlb', candidates, {}, d.materialId);
    if (resolved.ok && resolved.value.chosenBy === 'rule' && resolved.value.chosen.value !== undefined) {
      out.push({ kind: 'required_hlb', materialId: d.materialId, value: resolved.value.chosen.value, sourceId: resolved.value.chosen.sourceId });
    }
  }
  return ok(out);
}

export interface TestedMark {
  kind: TestedKind;
  materialId: string;
  value: string;
  formulaId: string;
  formulaCode: string;
  version: number;
  trialId: string;
  trialCode: string;
  /** ISO date the stability result was measured. */
  testedOn: string;
  confidence: Confidence;
}

export interface UsedValue {
  kind: TestedKind;
  materialId: string;
  value: string;
}

export interface TestedTag {
  kind: TestedKind;
  materialId: string;
  value: string;
  /** Newest test first (then formula code, then version, descending). */
  marks: TestedMark[];
}

function byRecency(a: TestedMark, b: TestedMark): number {
  return b.testedOn.localeCompare(a.testedOn) || a.formulaCode.localeCompare(b.formulaCode) || b.version - a.version;
}

/** The tags of the used values that have a record: same material, same kind and the same number. */
export function testedOnTags(used: readonly UsedValue[], marks: readonly TestedMark[]): TestedTag[] {
  const tags: TestedTag[] = [];
  for (const u of used) {
    const x = parseDec(u.value);
    if (x === null) {
      continue;
    }
    const hits = marks
      .filter((m) => m.kind === u.kind && m.materialId === u.materialId && parseDec(m.value)?.eq(x) === true)
      .sort(byRecency);
    if (hits.length > 0) {
      tags.push({ kind: u.kind, materialId: u.materialId, value: u.value, marks: hits });
    }
  }
  return tags;
}
