import type { Confidence } from './contract';
import type { SourcedScalar } from './core/parse';

/**
 * TEST INPUTS ONLY (sourceId 'TEST-FIXTURE'). Nominal grade values used to exercise the formulas;
 * they are not engine data and are never imported by production code.
 */
export const fixture = (value: string, unit?: SourcedScalar['unit'], confidence: Confidence = 'L2_supplier_tds'): SourcedScalar => ({
  value,
  ...(unit !== undefined && { unit }),
  sourceId: 'TEST-FIXTURE',
  confidence,
  validFrom: '2026-01-01',
});
