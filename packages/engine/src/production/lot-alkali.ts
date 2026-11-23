/**
 * Alkali of a batch recomputed with the values of the lots actually used (Q-03). Reuses soapLye (F-06) and neutralize (F-05):
 * nothing is calculated again here. The PLANNED amount uses the reference values the formula was designed with; the LOT amount
 * uses the COA values of the lots weighed. A lot value that is missing stops the lot calculation and is reported by name
 * (`missing[]`); it never falls back to the reference value, because that would hide that the COA is absent.
 */
import type { SourcedScalar } from '../core/parse';
import { dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { Quantity } from '../core/quantity';
import { neutralize } from '../neutralize';
import { soapLye, type SapUnit } from '../soap';
import type { StrongBase } from '../base-equivalent';
import type { ConfidencePolicy } from '../trace/sourced';
import { evaluateDeviation, type Deviation } from './deviation';

export const LOT_REASONS = {
  COA_MISSING: 'production.lot_coa_missing',
} as const;

export interface LotSoapOil {
  oilId: string;
  massG: string;
  sapUnit: SapUnit;
  /** The value the formula was designed with. */
  planned: SourcedScalar;
  /** The SAP on the COA of the lot weighed; absent = no COA for this lot. */
  lot?: SourcedScalar | null;
}

export interface LotSoapInput {
  kind: 'soap';
  base: StrongBase;
  oils: readonly LotSoapOil[];
  superfatPct: string;
  purityPlanned: SourcedScalar;
  purityLot?: SourcedScalar | null;
  policy?: ConfidencePolicy;
}

export interface LotNeutralizeInput {
  kind: 'neutralize';
  base: StrongBase;
  form: 'solid' | 'solution';
  acidId: string;
  acidMassG: string;
  acidValuePlanned: SourcedScalar;
  acidValueLot?: SourcedScalar | null;
  strengthPlanned: SourcedScalar;
  strengthLot?: SourcedScalar | null;
  policy?: ConfidencePolicy;
}

export interface LotAlkali {
  kind: 'soap' | 'neutralize';
  base: StrongBase;
  /** Alkali on the formula's reference values, g (soap: the lye weighed; neutralize: the reagent weighed). */
  plannedG: string;
  /** Alkali on the lots' COA values, g. */
  lotG: string;
  /** lot - planned, with the percentage; the verdict is only filled when a threshold is given. */
  deviation: Deviation;
}

const NO_POLICY: ConfidencePolicy = {};

/** The lot values that must be on a COA for the calculation, each named: a hole in any of them stops it. */
function lotGaps(entries: readonly { materialId?: string; property: string; value: SourcedScalar | null | undefined }[]) {
  return entries
    .filter((e) => e.value === undefined || e.value === null)
    .map((e) => ({ ...(e.materialId === undefined ? {} : { materialId: e.materialId }), property: e.property, reason: LOT_REASONS.COA_MISSING }));
}

export function recomputeAlkaliWithLots(input: LotSoapInput | LotNeutralizeInput, thresholdPct?: string | null): Result<LotAlkali> {
  return input.kind === 'soap' ? soap(input, thresholdPct) : neutral(input, thresholdPct);
}

function soap(input: LotSoapInput, thresholdPct?: string | null): Result<LotAlkali> {
  const gaps = lotGaps([
    ...input.oils.map((o) => ({ materialId: o.oilId, property: 'sap.lot', value: o.lot })),
    { property: 'purity.lot', value: input.purityLot },
  ]);
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const policy = input.policy ?? NO_POLICY;
  const common = { base: input.base, superfatPct: input.superfatPct, policy };
  const oilsOf = (useLot: boolean) =>
    input.oils.map((o) => ({
      oilId: o.oilId,
      mass: new Quantity(o.massG, 'g'),
      sap: { unit: o.sapUnit, typ: o.planned, ...(useLot ? { lot: o.lot as SourcedScalar } : {}) },
    }));
  const planned = soapLye({ ...common, oils: oilsOf(false), purity: input.purityPlanned });
  if (!planned.ok) {
    return planned;
  }
  const actual = soapLye({ ...common, oils: oilsOf(true), purity: input.purityLot as SourcedScalar });
  if (!actual.ok) {
    return actual;
  }
  return finish('soap', input.base, planned.value.used.value, actual.value.used.value, thresholdPct, [...planned.warnings, ...actual.warnings]);
}

function neutral(input: LotNeutralizeInput, thresholdPct?: string | null): Result<LotAlkali> {
  const gaps = lotGaps([
    { materialId: input.acidId, property: 'acid_value.lot', value: input.acidValueLot },
    { property: 'strength.lot', value: input.strengthLot },
  ]);
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const base = { acidMass: new Quantity(input.acidMassG, 'g'), acidId: input.acidId, base: input.base, form: input.form, policy: input.policy ?? NO_POLICY };
  const planned = neutralize({ ...base, acidValue: input.acidValuePlanned, strength: input.strengthPlanned });
  if (!planned.ok) {
    return planned;
  }
  const actual = neutralize({ ...base, acidValue: input.acidValueLot as SourcedScalar, strength: input.strengthLot as SourcedScalar });
  if (!actual.ok) {
    return actual;
  }
  return finish('neutralize', input.base, planned.value.reagentMass.value, actual.value.reagentMass.value, thresholdPct, [...planned.warnings, ...actual.warnings]);
}

function finish(kind: 'soap' | 'neutralize', base: StrongBase, plannedG: string, lotG: string, thresholdPct: string | null | undefined, warnings: string[]): Result<LotAlkali> {
  const deviation = evaluateDeviation({ planned: plannedG, actual: lotG, unit: 'g', thresholdPct, property: `${kind}.alkali` });
  if (!deviation.ok) {
    return deviation;
  }
  return ok({ kind, base, plannedG: dec(plannedG).toFixed(), lotG: dec(lotG).toFixed(), deviation: deviation.value }, warnings);
}
