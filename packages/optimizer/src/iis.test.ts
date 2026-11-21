import { describe, expect, it } from 'vitest';
import { OPTIMIZER_MESSAGE_KEYS } from './messages';

const known = (key: string, params: Record<string, string>): boolean => {
  const spec = OPTIMIZER_MESSAGE_KEYS.find((s) => s.key === key);
  return spec !== undefined && spec.params.every((p) => p in params);
};
import { optimize } from './optimize';
import { case2, mat, profile, seededShuffle } from './test-fixtures';


describe('J-06 infeasibility', () => {
  it('max cost below the cheapest feasible: minimal conflict set, key, params and Arabic text', async () => {
    // case 2 cheapest cost is 26.225 EGP/kg; cap it at 20.
    // Deletion filter (fixed order active.min, fn.amphoteric.min, maxCost): dropping active.min makes it feasible (keep);
    // dropping fn.amphoteric.min stays infeasible (cost for 12 active via S1 alone is 24.7 > 20) -> not needed; maxCost kept.
    // cheapest without the cap = 26.225 -> rounded up 26.23, gap 6.23.
    const out = await optimize(case2({ profile: profile({ totalActiveMinPct: '12', functionBounds: [{ function: 'amphoteric', min: '5', max: null }], maxCostEGPperKg: '20' }) }));
    expect(out.ok).toBe(false);
    expect(out.status).toBe('infeasible');
    expect(out.formula).toEqual([]);
    expect(out.conflicts).toEqual([
      { id: 'active.min', key: 'optimizer.infeasible.active_min_too_high', params: { limitPct: '12' } },
      { id: 'maxCost', key: 'optimizer.infeasible.max_cost_too_low', params: { limitEGPperKg: '20', minAchievableEGPperKg: '26.23', gapEGPperKg: '6.23' } },
    ]);
    for (const c of out.conflicts) {
      expect(known(c.key, c.params)).toBe(true);
    }
  });

  it('target retail price too low: waterfall -> cost cap -> optimizer.infeasible.target_price_too_low', async () => {
    // shelf 22.80 incl. 14 % VAT -> 20; retailer 25 % markup -> 16; wholesaler 25 % -> 12.8; distribution 0.8 -> 12;
    // factory 20 % -> max unit cost 10; non-formula (packaging, conversion) 2; fill 0.5 kg -> formula cap (10 − 2)/0.5 = 16 EGP/kg
    const out = await optimize(case2({
      profile: profile({ totalActiveMinPct: '12', functionBounds: [{ function: 'amphoteric', min: '5', max: null }], retailPrice: '22.8', per: 'pack' }),
      reversePricing: { vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '0.8', factoryMarginPct: '20', marginBasis: 'markup_on_cost', packFillKg: '0.5', nonFormulaCostPerUnit: '2' },
    }));
    expect(out.status).toBe('infeasible');
    expect(out.reversePricing?.maxFormulaCostPerKg).toBe('16');
    const target = out.conflicts.find((c) => c.id === 'targetPrice');
    expect(target).toEqual({ id: 'targetPrice', key: 'optimizer.infeasible.target_price_too_low', params: { limitEGPperKg: '16', minAchievableEGPperKg: '26.23', gapEGPperKg: '10.23' } });
    expect(known(target?.key ?? '', target?.params ?? {})).toBe(true);
  });

  it('a reachable target price solves, and the cap is binding information', async () => {
    // same waterfall with shelf 57 -> 50 -> 40 -> 32 -> 30 -> max unit cost 25; non-formula 2.5, fill 0.5 -> (25 − 2.5)/0.5 = 45 EGP/kg >= 26.225
    const out = await optimize(case2({
      profile: profile({ totalActiveMinPct: '12', functionBounds: [{ function: 'amphoteric', min: '5', max: null }], retailPrice: '57', per: 'pack' }),
      reversePricing: { vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '2', factoryMarginPct: '20', marginBasis: 'markup_on_cost', packFillKg: '0.5', nonFormulaCostPerUnit: '2.5' },
    }));
    expect(out.status).toBe('optimal');
    expect(out.reversePricing?.maxFormulaCostPerKg).toBe('45');
    expect(out.costPerKg).toBe('26.225');
  });

  it('two non-cost constraints in conflict; unrelated constraints are not reported', async () => {
    // S1 is the only function member: anionic >= 60 forces S1 >= 60 -> active 24, but active max is 20.
    const out = await optimize({
      profile: profile({
        totalActiveMaxPct: '20',
        functionBounds: [{ function: 'anionic', min: '60', max: null }],
        maxCostEGPperKg: '1000',
        materialBounds: [{ materialId: 'WATER', min: null, max: '100' }],
      }),
      materials: [mat('S1', '80', '40', 'anionic'), mat('WATER', '1', '0')],
      qsMaterialId: 'WATER',
      policy: { requiredFields: [] },
    });
    expect(out.status).toBe('infeasible');
    expect(out.conflicts.map((c) => [c.id, c.key])).toEqual([
      ['active.max', 'optimizer.infeasible.active_max_too_low'],
      ['fn.anionic.min', 'optimizer.infeasible.function_min_too_high'],
    ]);
    for (const c of out.conflicts) {
      expect(known(c.key, c.params)).toBe(true);
    }
  });

  it('the conflict set is the same for every input order', async () => {
    const input = case2({ profile: profile({ totalActiveMinPct: '12', functionBounds: [{ function: 'amphoteric', min: '5', max: null }], maxCostEGPperKg: '20' }) });
    const ref = await optimize(input);
    for (let seed = 1; seed <= 6; seed += 1) {
      const out = await optimize({ ...input, materials: seededShuffle(input.materials, seed) });
      expect(out.conflicts).toEqual(ref.conflicts);
      expect(out.outputHash).toBe(ref.outputHash);
    }
  });

  it('a cost cap negative after non-formula costs is reported as target price too low', async () => {
    const out = await optimize(case2({
      profile: profile({ totalActiveMinPct: '12', retailPrice: '22.8', per: 'kg' }),
      reversePricing: { vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '0.8', factoryMarginPct: '20', marginBasis: 'markup_on_cost', nonFormulaCostPerUnit: '11' },
    }));
    expect(out.status).toBe('infeasible');
    expect(out.conflicts.find((c) => c.id === 'targetPrice')?.key).toBe('optimizer.infeasible.target_price_too_low');
  });
});
