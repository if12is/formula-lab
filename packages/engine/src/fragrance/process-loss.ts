/**
 * Process loss of a fragrance batch (L-04): ageing (maceration), chilling and filtration.
 * Each stage loss is a % of the mass ENTERING that stage, taken from the user's own trials (a sourced value;
 * there is no built-in default). The stages compound:
 *   yield = Π (1 - lossᵢ / 100), total loss % = 100 x (1 - yield)
 * A stage that has no value is `missing`, never zero. An explicit 0 (a trial that measured no loss) is a value.
 * The total loss feeds the cost as the wastage of productCostBreakdown (perfume-cost.ts).
 */
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { all, flatMap, missing, ok, type Result } from '../core/result';
import { resolveSourcedNumber } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

export const PROCESS_LOSS_REASONS = {
  TOTAL_LOSS: 'fragrance.loss_must_be_below_100',
} as const;

export const LOSS_STAGES = ['ageing', 'chilling', 'filtration'] as const;
export type LossStage = (typeof LOSS_STAGES)[number];

export interface ProcessLossInput {
  /** Loss % of the mass entering each stage, from a trial. */
  stages: Partial<Record<LossStage, SourcedScalar>>;
  policy: ConfidencePolicy;
}

export interface ProcessLoss {
  stages: { stage: LossStage; lossPct: Traced }[];
  /** Π (1 - lossᵢ/100) as a fraction (not a percent). */
  yield: Traced;
  /** 100 x (1 - yield), %w/w of the batch. */
  totalLossPct: Traced;
}

/** Combined loss of the three stages; every stage without a value is listed. */
export function processLoss(input: ProcessLossInput): Result<ProcessLoss> {
  const resolved = all(
    LOSS_STAGES.map((stage) =>
      resolveSourcedNumber(
        input.stages[stage],
        { property: `loss.${stage}`, label: `${stage} loss (% of the mass entering the stage)`, unit: '%w/w', min: '0', max: '100' },
        input.policy,
      ),
    ),
  );
  return flatMap(resolved, (values): Result<ProcessLoss> => {
    // A stage that loses everything leaves nothing to cost per unit (and would divide by zero downstream).
    const total = values.flatMap((v, i) => (decOf(v.leaf).gte(100) ? [{ property: `loss.${LOSS_STAGES[i]}`, reason: PROCESS_LOSS_REASONS.TOTAL_LOSS }] : []));
    if (total.length > 0) {
      return missing(total);
    }
    const stages = values.map((v, i) => ({ stage: LOSS_STAGES[i] as LossStage, lossPct: v.leaf }));
    const fractions = stages.map((s) => dec('1').minus(decOf(s.lossPct).div(100)));
    const yieldValue = fractions.reduce((acc, f) => acc.mul(f), dec('1'));
    const yieldLeaf = traced('mul', 'Yield = Π (1 - stage loss/100)', yieldValue, '%w/w', stages.map((s) => s.lossPct));
    const totalLoss = traced('sub', 'Total loss % = 100 x (1 - yield)', dec('100').mul(dec('1').minus(yieldValue)), '%w/w', [yieldLeaf]);
    return ok({ stages, yield: yieldLeaf, totalLossPct: totalLoss });
  });
}
