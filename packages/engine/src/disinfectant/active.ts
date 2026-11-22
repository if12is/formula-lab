/**
 * Concentration of the disinfecting active in a finished product (K-05). Pure, decimal.js only. No limit or efficacy threshold lives
 * here: the concentration is computed from the sourced strength of each material, and a requirement is a number the caller types.
 *
 *   alcohol (%v/v): ethanol mass = sum(m_i x w_i/100), w_mix = ethanol mass / total mass x 100 (%w/w),
 *                   then %v/v from OIML R 22 (core/alcohol): the basis change is a table lookup, never a linear guess.
 *   quaternary ammonium (%w/w): the active matter of the blend (active/activeMatterOfBlend): sum(m_i x a_i) / sum(m_i).
 *
 * A material that is meant to carry the active needs its strength from a COA/TDS with a stated basis; one declared as carrying none
 * contributes 0 by that declaration. A missing value is reported in `missing[]`, never read as zero.
 */
import { vvFromWW, type OimlTable } from '../core/alcohol';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, flatMap, map, missing, type Result } from '../core/result';
import { activeMatterOfBlend, type ActiveLine } from '../active';
import { resolveEthanolSource } from '../fragrance';
import { resolveMassG, resolveUserNumber, type SourcedNumberSpec } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

export const DISINFECTANT_REASONS = {
  NO_LINES: 'disinfectant.no_lines',
  NO_ETHANOL_LINE: 'disinfectant.no_ethanol_line',
  NON_ETHANOL_HAS_STRENGTH: 'disinfectant.declared_non_ethanol_has_strength',
} as const;

export interface EthanolLine {
  materialId: string;
  mass: Quantity;
  /** true: the material is meant to carry ethanol (its strength is required). false: declared to carry none. */
  contributesEthanol: boolean;
  /** COA/TDS strength; its unit ('%v/v' or '%w/w') is the stated basis. Absent on a declared non-ethanol material. */
  strength?: SourcedScalar;
}

export interface AlcoholDisinfectantInput {
  kind: 'alcohol';
  lines: readonly EthanolLine[];
  policy: ConfidencePolicy;
  /** A required minimum typed by the caller (%v/v). The engine knows no threshold of its own. */
  requiredMinPctVV?: string;
  /** Test seam: defaults to the shipped OIML R 22 table. */
  table?: OimlTable;
}

export interface QuatDisinfectantInput {
  kind: 'quat';
  /** The materials with their active matter %w/w (the quaternary ammonium compounds carry it, the rest declare 0 with a source). */
  lines: readonly ActiveLine[];
  policy: ConfidencePolicy;
}

export type DisinfectantActiveInput = AlcoholDisinfectantInput | QuatDisinfectantInput;

export interface AlcoholDisinfectantActive {
  kind: 'alcohol';
  /** Ethanol strength of the finished product, %v/v (OIML R 22 from the mass balance). */
  concentration: Traced;
  ethanolPctWW: Traced;
  ethanolMass: Traced;
  totalMass: Traced;
  /** concentration - requiredMinPctVV, only when a requirement was given; negative = below it. */
  requirementMargin?: Traced;
  meetsRequirement?: boolean;
}

export interface QuatDisinfectantActive {
  kind: 'quat';
  /** Active matter of the quaternary ammonium in the finished product, %w/w. */
  concentration: Traced;
  activeMass: Traced;
  totalMass: Traced;
}

export type DisinfectantActive = AlcoholDisinfectantActive | QuatDisinfectantActive;

interface EthanolTerm {
  mass: Traced;
  ethanol: Traced;
}

function ethanolTerm(line: EthanolLine, policy: ConfidencePolicy, table?: OimlTable): Result<EthanolTerm> {
  const id = line.materialId;
  const mass = resolveMassG(id, 'mass', `${id} mass (g)`, line.mass);
  if (!line.contributesEthanol) {
    if (line.strength !== undefined) {
      return missing([{ materialId: id, property: 'ethanolStrength', reason: DISINFECTANT_REASONS.NON_ETHANOL_HAS_STRENGTH }]);
    }
    return map(mass, (m) => ({ mass: m.leaf, ethanol: traced('input', `${id} ethanol mass (declared none)`, dec('0'), 'g') }));
  }
  const strength = resolveEthanolSource(line.strength, { materialId: id, property: 'ethanolStrength', label: `${id} ethanol strength` }, policy, table);
  return map(all([mass, strength] as const), ([m, s]) => ({
    mass: m.leaf,
    ethanol: traced('mul', `${id} ethanol mass = m x w/100`, m.value.mul(decOf(s.pctWW)).div(100), 'g', [m.leaf, s.pctWW]),
  }));
}

const REQUIRED_MIN_SPEC: SourcedNumberSpec = { unit: '%v/v', min: '0', minExclusive: true, max: '100', property: 'requiredMinPctVV', label: 'Required minimum ethanol (%v/v)' };

function alcoholActive(input: AlcoholDisinfectantInput): Result<DisinfectantActive> {
  if (input.lines.length === 0) {
    return missing([{ property: 'lines', reason: DISINFECTANT_REASONS.NO_LINES }]);
  }
  if (!input.lines.some((l) => l.contributesEthanol)) {
    return missing([{ property: 'lines', reason: DISINFECTANT_REASONS.NO_ETHANOL_LINE }]);
  }
  const terms = all(input.lines.map((l) => ethanolTerm(l, input.policy, input.table)));
  const required = input.requiredMinPctVV === undefined ? undefined : resolveUserNumber(input.requiredMinPctVV, REQUIRED_MIN_SPEC);
  if (required !== undefined && !required.ok) {
    // Report the requirement problem together with any gap in the lines.
    return missing([...(terms.ok ? [] : terms.missing), ...required.missing]);
  }
  return flatMap(terms, (list): Result<DisinfectantActive> => {
    const totalMass = list.reduce((s, t) => s.plus(decOf(t.mass)), dec('0'));
    const ethanolMass = list.reduce((s, t) => s.plus(decOf(t.ethanol)), dec('0'));
    const totalT = traced('sum', 'Total mass (g)', totalMass, 'g', list.map((t) => t.mass));
    const ethanolT = traced('sum', 'Ethanol mass (g) = sum(m x w/100)', ethanolMass, 'g', list.map((t) => t.ethanol));
    const ww = traced('div', 'Ethanol of the product (%w/w) = ethanol mass / total mass x 100', ethanolMass.div(totalMass).mul(100), '%w/w', [ethanolT, totalT]);
    return map(vvFromWW(decOf(ww), input.table), (vv): DisinfectantActive => {
      const concentration = traced('oiml', 'Ethanol of the product (%v/v) (OIML R 22)', vv, '%v/v', [ww]);
      const base: AlcoholDisinfectantActive = { kind: 'alcohol', concentration, ethanolPctWW: ww, ethanolMass: ethanolT, totalMass: totalT };
      if (required === undefined || !required.ok) {
        return base;
      }
      const margin = vv.minus(required.value.value);
      return {
        ...base,
        requirementMargin: traced('sub', 'Margin over the required minimum (%v/v)', margin, '%v/v', [concentration, required.value.leaf]),
        meetsRequirement: margin.gte(0),
      };
    });
  });
}

/** The disinfecting active of a product: ethanol %v/v (OIML R 22) or quaternary ammonium %w/w from the sourced strengths. */
export function disinfectantActive(input: DisinfectantActiveInput): Result<DisinfectantActive> {
  if (input.kind === 'alcohol') {
    return alcoholActive(input);
  }
  return map(activeMatterOfBlend({ lines: input.lines, policy: input.policy }), (blend): DisinfectantActive => ({
    kind: 'quat',
    concentration: blend.activeMatterPct,
    activeMass: blend.activeMass,
    totalMass: blend.totalMass,
  }));
}
