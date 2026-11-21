/**
 * Molar masses (g/mol) of the two strong bases used in neutralization and saponification.
 * They are derived constants (sum of standard atomic weights), shared by every module that
 * converts between the NaOH and KOH bases, so they live here once.
 */
import { D, type Dec } from './decimal';

export const MOLAR_MASS = {
  NaOH: '39.997',
  KOH: '56.106',
} as const;

export const MOLAR_MASS_SOURCES = {
  sourceId: 'IUPAC-STD-ATOMIC-WEIGHTS',
  citation: 'IUPAC Commission on Isotopic Abundances and Atomic Weights, standard atomic weights (Na, K, O, H)',
  derivation: 'M = sum of standard atomic weights, rounded to 3 decimals: NaOH = Na + O + H; KOH = K + O + H',
  unit: 'g/mol',
  values: MOLAR_MASS,
} as const;

/** M_NaOH / M_KOH: grams of NaOH that neutralize / saponify the same moles as 1 g of KOH. */
export function naohPerKoh(): Dec {
  return new D(MOLAR_MASS.NaOH).div(MOLAR_MASS.KOH);
}
