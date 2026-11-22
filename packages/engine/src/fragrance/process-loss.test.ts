import { describe, expect, it } from 'vitest';
import { fixture } from '../test-fixtures';
import { leavesOf } from '../trace/trace';
import { processLoss } from './process-loss';

/* Loss % values are TEST INPUTS (sourceId TEST-FIXTURE): the trials of the user supply the real ones. */
const stages = (ageing: string | undefined, chilling: string | undefined, filtration: string | undefined) => ({
  ...(ageing !== undefined && { ageing: fixture(ageing, '%w/w') }),
  ...(chilling !== undefined && { chilling: fixture(chilling, '%w/w') }),
  ...(filtration !== undefined && { filtration: fixture(filtration, '%w/w') }),
});

describe('processLoss (L-04)', () => {
  it('hand case 1: 10 % ageing only (0 elsewhere): yield 0.9, total loss 10 %', () => {
    const r = processLoss({ stages: stages('10', '0', '0'), policy: {} });
    expect(r.ok && [r.value.yield.value, r.value.totalLossPct.value]).toEqual(['0.9', '10']);
  });

  it('hand case 2: 10 %, 10 %, 0 % compound: 0.9 x 0.9 = 0.81, total 19 % (not 20 %)', () => {
    const r = processLoss({ stages: stages('10', '10', '0'), policy: {} });
    expect(r.ok && [r.value.yield.value, r.value.totalLossPct.value]).toEqual(['0.81', '19']);
  });

  it('hand case 3: 50 % x 3 stages: 0.5 ^ 3 = 0.125, total 87.5 %; every leaf is a sourced stage value', () => {
    const r = processLoss({ stages: stages('50', '50', '50'), policy: {} });
    expect(r.ok && [r.value.yield.value, r.value.totalLossPct.value]).toEqual(['0.125', '87.5']);
    if (r.ok) {
      expect(leavesOf(r.value.totalLossPct.trace).every((l) => l.sourceId === 'TEST-FIXTURE')).toBe(true);
    }
  });

  it('a stage without a value is missing (all of them listed), never zero', () => {
    const r = processLoss({ stages: stages('10', undefined, undefined), policy: {} });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing.map((m) => [m.property, m.reason])).toEqual([
      ['loss.chilling', 'input.missing'],
      ['loss.filtration', 'input.missing'],
    ]);
  });

  it('100 % or a negative loss is rejected; an L7 estimate needs a signed override', () => {
    const bad = processLoss({ stages: stages('100', '0', '0'), policy: {} });
    expect(!bad.ok && bad.missing[0]?.reason).toBe('fragrance.loss_must_be_below_100');
    const negative = processLoss({ stages: stages('-1', '0', '0'), policy: {} });
    expect(!negative.ok && negative.missing[0]?.reason).toBe('input.out_of_range');
    const estimate = processLoss({ stages: { ...stages('10', '0', '0'), ageing: fixture('10', '%w/w', 'L7_estimate') }, policy: {} });
    expect(!estimate.ok && estimate.missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
  });
});
