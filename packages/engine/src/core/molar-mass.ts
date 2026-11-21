/**
 * Molar masses (g/mol) of the species the engine converts between: the two strong bases used in
 * neutralization and saponification (NaOH, KOH) and the two bases an available-chlorine figure is
 * quoted on (NaOCl, Cl2). They are derived constants (sum of standard atomic weights), shared by
 * every module that converts between those bases, so they live here once.
 */
import { D, type Dec } from './decimal';

export const MOLAR_MASS = {
  NaOH: '39.997',
  KOH: '56.106',
  Cl2: '70.90',
  NaOCl: '74.44',
} as const;

export const MOLAR_MASS_SOURCES = {
  sourceId: 'IUPAC-STD-ATOMIC-WEIGHTS',
  citation: 'IUPAC Commission on Isotopic Abundances and Atomic Weights, standard atomic weights (Na, K, O, H, Cl)',
  derivation: 'M = sum of standard atomic weights, rounded to 3 decimals: NaOH = Na + O + H; KOH = K + O + H; Cl2 = 2 x Cl (Cl = 35.45); NaOCl = Na + O + Cl (22.990 + 15.999 + 35.45 = 74.439, rounded to 74.44)',
  unit: 'g/mol',
  values: MOLAR_MASS,
} as const;

/** M_NaOH / M_KOH: grams of NaOH that neutralize / saponify the same moles as 1 g of KOH. */
export function naohPerKoh(): Dec {
  return new D(MOLAR_MASS.NaOH).div(MOLAR_MASS.KOH);
}

/** M_Cl2 / M_NaOCl: available Cl2 per gram of NaOCl (1 mol NaOCl = 1 mol available Cl2). */
export function cl2PerNaocl(): Dec {
  return new D(MOLAR_MASS.Cl2).div(MOLAR_MASS.NaOCl);
}
