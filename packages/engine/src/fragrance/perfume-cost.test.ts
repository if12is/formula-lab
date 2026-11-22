import { describe, expect, it } from 'vitest';
import { dec } from '../core/decimal';
import { money, scalar, usdRate } from '../costing/test-fixtures';
import { fixture } from '../test-fixtures';
import { leavesOf } from '../trace/trace';
import { perfumeCost, PERFUME_COST_WARNINGS, type PerfumeCostInput, type PerfumePackagingSlotInput } from './perfume-cost';

/*
 * Batch of 1 kg (the hand check of fine-fragrance.test.ts, OIML row 40.0 %w/w = 47.3947631607 %v/v):
 *   oil 20 %, fixative 1 %, carrier 79 % from 80 %w/w stock -> stock ethanol 39.5 %, water 39.5 %.
 * Prices EGP/kg (TEST INPUTS, TEST-FIXTURE): oil 5000, alcohol 100, water 2, fixative 1000.
 *   per kg before loss = 0.2 x 5000 + 0.395 x 100 + 0.395 x 2 + 0.01 x 1000 = 1000 + 39.5 + 0.79 + 10 = 1050.29
 * Fill 100 mL x measured density 0.9 g/mL = 0.09 kg  ->  before loss: oil 90, alcohol 3.555, water 0.0711, fixative 0.9
 *   = 94.5261 per bottle.  Bottle 12, sprayer 3, box 2 (EGP, reject 0 unless stated).
 */
const item = (itemId: string, price: string, rejectPct = '0'): PerfumePackagingSlotInput => ({
  itemId,
  price: money(price),
  qtyPerUnit: scalar('1'),
  rejectPct: scalar(rejectPct),
});
const base = (over: Partial<PerfumeCostInput> = {}): PerfumeCostInput => ({
  fine: {
    fragranceId: 'OIL',
    fragranceOilPct: '20',
    fixativePct: '1',
    alcoholSource: fixture('80', '%w/w', 'L1_coa_batch'),
    alcoholMaterialId: 'ETOH',
    alcoholTargetPctVV: '47.3947631607',
  },
  prices: { oil: money('5000'), alcohol: money('100'), water: money('2'), fixative: money('1000') },
  loss: { ageing: fixture('0', '%w/w'), chilling: fixture('0', '%w/w'), filtration: fixture('0', '%w/w') },
  productDensity: { value: '0.9', unit: 'g/mL', sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch', validFrom: '2026-01-01' },
  fill: { value: '100', unit: 'mL', sourceId: 'TEST-FIXTURE', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' },
  packaging: { bottle: item('bottle', '12'), sprayer: item('sprayer', '3'), box: item('box', '2') },
  policy: {},
  ...over,
});
const run = (over: Partial<PerfumeCostInput> = {}) => {
  const r = perfumeCost(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<PerfumeCostInput>) => {
  const r = perfumeCost(base(over));
  return r.ok ? null : r.missing.map((m) => [m.materialId, m.property, m.reason]);
};
const r10 = (v: string) => dec(v).toDecimalPlaces(10).toFixed();
const byKey = (r: ReturnType<typeof run>) => Object.fromEntries(r.value.components.map((c) => [c.key, r10(c.perUnit.value)]));

describe('perfumeCost (L-10)', () => {
  it('hand case 1, no loss: 94.5261 product + 17 packaging = 111.5261, every component by hand', () => {
    const r = run();
    expect(byKey(r)).toEqual({ oil: '90', alcohol: '3.555', water: '0.0711', fixative: '0.9', loss: '0', bottle: '12', sprayer: '3', box: '2' });
    expect(r10(r.value.directTotal.value)).toBe('111.5261');
    expect(r10(r.value.unitCost.sections.product.value)).toBe('94.5261');
    expect(r.warnings).toContain(PERFUME_COST_WARNINGS.EXCLUDES_REST);
  });

  it('hand case 2, 20 % ageing loss + 20 % bottle breakage: product 94.5261 / 0.8 = 118.157625 (loss 23.631525), bottle 12 / 0.8 = 15', () => {
    const r = run({
      loss: { ageing: fixture('20', '%w/w'), chilling: fixture('0', '%w/w'), filtration: fixture('0', '%w/w') },
      packaging: { bottle: item('bottle', '12', '20'), sprayer: item('sprayer', '3'), box: item('box', '2') },
    });
    expect(byKey(r)).toMatchObject({ oil: '90', loss: '23.631525', bottle: '15', sprayer: '3', box: '2' });
    expect(r10(r.value.unitCost.sections.product.value)).toBe('118.157625');
    expect(r10(r.value.directTotal.value)).toBe('138.157625');
    expect(r.value.loss.totalLossPct.value).toBe('20');
  });

  it('hand case 3, USD alcohol (2 USD x 50 = 100 EGP) and losses 10 % + 10 % + 0: yield 0.81, product 94.5261 / 0.81, full cost with an energy line', () => {
    const r = run({
      prices: { oil: money('5000'), alcohol: money('2', 'USD'), water: money('2'), fixative: money('1000') },
      fxRates: [usdRate],
      loss: { ageing: fixture('10', '%w/w'), chilling: fixture('10', '%w/w'), filtration: fixture('0', '%w/w') },
      rest: { conversion: { lines: [{ id: 'energy', kind: 'energy', rate: money('2'), qtyPerUnit: scalar('0.01') }], overheads: [] }, quality: { batchTests: [], regulatory: [] }, oneTime: [] },
    });
    // 94.5261 / 0.81 = 116.69888888...; loss = that - 94.5261 = 22.17278888...; conversion 2 x 0.01 = 0.02
    expect(r10(r.value.unitCost.sections.product.value)).toBe('116.6988888889');
    expect(r10(byKey(r).loss as string)).toBe('22.1727888889');
    expect(r.value.loss.totalLossPct.value).toBe('19');
    expect(r10(r.value.directTotal.value)).toBe('133.6988888889');
    expect(r10(r.value.unitCost.total.value)).toBe('133.7188888889');
    expect(r.warnings).not.toContain(PERFUME_COST_WARNINGS.EXCLUDES_REST);
    // the trace ends in sourced prices, losses and the dated FX rate only
    expect(leavesOf(r.value.directTotal.trace).some((l) => l.sourceId === 'FX-TEST')).toBe(true);
  });

  it('missing is missing: a loss stage, the sprayer, the water price, the fill, each reported', () => {
    expect(gaps({ loss: { ageing: fixture('0', '%w/w') } })).toEqual([
      [undefined, 'loss.chilling', 'input.missing'],
      [undefined, 'loss.filtration', 'input.missing'],
    ]);
    expect(gaps({ packaging: { bottle: item('bottle', '12'), box: item('box', '2') } })).toEqual([[undefined, 'packaging.sprayer', 'fragrance.packaging_item_missing']]);
    expect(gaps({ prices: { oil: money('5000'), alcohol: money('100'), fixative: money('1000') } })).toEqual([['water', 'price', 'cost.price_missing']]);
    // a slot with no price reports the price only, and an omitted piece count is 1 with the price's own source
    expect(gaps({ packaging: { bottle: { rejectPct: scalar('0') }, sprayer: item('sprayer', '3'), box: item('box', '2') } })).toEqual([['bottle', 'price', 'cost.price_missing']]);
    const implicit = run({ packaging: { bottle: { price: money('12'), rejectPct: scalar('0') }, sprayer: { price: money('3'), rejectPct: scalar('0') }, box: { price: money('2'), rejectPct: scalar('0') } } });
    expect(r10(implicit.value.directTotal.value)).toBe('111.5261');
    expect(gaps({ fill: undefined })).toEqual([[undefined, 'fillActual', 'fill.actual_required']]);
  });
});
