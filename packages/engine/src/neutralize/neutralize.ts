/**
 * Neutralization of a sulfonic acid (LABSA) with a strong base (F-05). Pure, decimal.js only.
 *   m_base(pure) = m_acid x AV/1000 x f          f = M_NaOH/M_KOH for NaOH, 1 for KOH
 *   m_reagent    = m_base(pure) / strength        strength = purity (solid) or concentration (solution), fraction
 * AV (mg KOH/g, includes free acid) and the reagent strength come from the lot COA.
 * The result is a THEORETICAL starting point: the final pH adjustment is measured.
 */
import type { SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, ok, type Result } from '../core/result';
import { baseEquivalentPerKoh, type StrongBase } from '../base-equivalent';
import { resolveMassG, resolveSourcedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

export const NEUTRALIZE_WARNINGS = {
  THEORETICAL_START: 'neutralize.theoretical_start_final_ph_measured',
} as const;

export type ReagentForm = 'solid' | 'solution';

export interface NeutralizeInput {
  acidMass: Quantity;
  acidId?: string;
  /** Acid value in mg KOH/g from the lot COA. */
  acidValue: SourcedScalar;
  base: StrongBase;
  baseId?: string;
  /** 'solid': strength is purity. 'solution': strength is the base concentration of the solution. */
  form: ReagentForm;
  /** % w/w, from the lot COA (purity of the solid, or concentration of the solution). */
  strength: SourcedScalar;
  policy: ConfidencePolicy;
}

export interface Neutralization {
  /** Base needed on a 100% basis. */
  pureBaseMass: Traced;
  /** What is actually weighed: solid grade or solution. */
  reagentMass: Traced;
  /** Water carried by the reagent (solution form only). */
  waterInReagent?: Traced;
}

export function neutralize(input: NeutralizeInput): Result<Neutralization> {
  const inputs = all([
    resolveMassG(input.acidId, 'acidMass', `${input.acidId ?? 'acid'} mass (g)`, input.acidMass),
    resolveSourcedNumber(
      input.acidValue,
      { materialId: input.acidId, property: 'acidValue', label: 'Acid value (mg KOH/g)', unit: 'mgKOH/g', min: '0', minExclusive: true },
      input.policy,
    ),
    resolveSourcedNumber(
      input.strength,
      {
        materialId: input.baseId,
        property: input.form === 'solid' ? 'purity' : 'concentration',
        label: `${input.baseId ?? input.base} ${input.form === 'solid' ? 'purity' : 'concentration'} (%)`,
        unit: '%w/w',
        min: '0',
        minExclusive: true,
        max: '100',
      },
      input.policy,
    ),
  ] as const);
  if (!inputs.ok) {
    return inputs;
  }
  const [acid, av, strength] = inputs.value;
  const factor = baseEquivalentPerKoh(input.base);
  const pure = acid.value.mul(av.value).div(1000).mul(decOf(factor));
  const pureT = traced('mul', `Pure ${input.base} = m x AV/1000 x f`, pure, 'g', [acid.leaf, av.leaf, factor]);
  const reagent = pure.div(strength.value).mul(100);
  const reagentT = traced('div', `${input.form === 'solid' ? 'Solid' : 'Solution'} ${input.base} mass = pure / strength`, reagent, 'g', [pureT, strength.leaf]);
  const warnings = [...inputs.warnings, NEUTRALIZE_WARNINGS.THEORETICAL_START];
  if (input.form === 'solid') {
    return ok({ pureBaseMass: pureT, reagentMass: reagentT }, warnings);
  }
  const water = traced('sub', 'Water in solution = solution - pure base', reagent.minus(pure), 'g', [reagentT, pureT]);
  return ok({ pureBaseMass: pureT, reagentMass: reagentT, waterInReagent: water }, warnings);
}
