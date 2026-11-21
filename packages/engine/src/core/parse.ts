import type { Confidence, SourcedValue } from '../contract';
import { D, isDecimalString, type Dec } from './decimal';

/**
 * A sourced scalar: SourcedValue whose unit is optional (HLB, iodine value and fatty-acid
 * percentages are dimensionless or already carried by the caller's convention).
 */
export type SourcedScalar = Omit<SourcedValue, 'unit'> & { unit?: SourcedValue['unit'] };

/** Parses a decimal string; returns null (never throws) for anything else, incl. JS numbers. */
export function parseDec(x: unknown): Dec | null {
  return isDecimalString(x) ? new D(x) : null;
}

/** Confidence rank, 1 (best, L1) .. 7 (worst, L7). */
export const CONFIDENCE_RANK: Readonly<Record<Confidence, number>> = {
  L1_coa_batch: 1,
  L2_supplier_tds: 2,
  L3_standard: 3,
  L4_regulatory_db: 4,
  L5_reference: 5,
  L6_community: 6,
  L7_estimate: 7,
};
