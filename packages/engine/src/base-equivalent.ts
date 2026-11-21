import { naohPerKoh, MOLAR_MASS, MOLAR_MASS_SOURCES } from './core/molar-mass';
import { referenceLeaf, traced, type Traced } from './trace/trace';

export type StrongBase = 'NaOH' | 'KOH';

/**
 * Mass of `base` equivalent (same moles) to 1 g of KOH, as a trace node:
 * KOH => 1; NaOH => M_NaOH / M_KOH. Used to express KOH-based values (acid value, SAP) in either base.
 */
export function baseEquivalentPerKoh(base: StrongBase): Traced {
  if (base === 'KOH') {
    return traced('const', 'KOH per KOH', '1', 'g');
  }
  const mNa = referenceLeaf('M_NaOH (g/mol)', MOLAR_MASS.NaOH, 'g', MOLAR_MASS_SOURCES.sourceId);
  const mK = referenceLeaf('M_KOH (g/mol)', MOLAR_MASS.KOH, 'g', MOLAR_MASS_SOURCES.sourceId);
  return traced('div', 'M_NaOH / M_KOH', naohPerKoh(), 'g', [mNa, mK]);
}
