import { describe, expect, it } from 'vitest';
import { fullCost, money, scalar, usdRate } from '../costing/test-fixtures';
import { leavesOf } from '../trace/trace';
import { packagingCostPerUnit, type PackagingBomItem } from './bom';
import { amortizeOneTimeCosts } from './one-time';
import { packagingShareReport } from './share-report';

// TEST-FIXTURE numbers (fictional prices, chosen so every figure is exact).
const item = (over: Partial<PackagingBomItem> & { itemId: string }): PackagingBomItem => ({
  level: 'unit', price: money('1'), qtyPerUnit: scalar('1'), rejectPct: scalar('0'), ...over,
});
const gapsOf = (r: ReturnType<typeof packagingCostPerUnit>) => (!r.ok ? r.missing.map((m) => [m.materialId, m.property, m.reason]) : []);

describe('H-03 packaging BOM cost per unit', () => {
  it('case 1: bottle, cap, label, carton and pallet share => 8.5 EGP', () => {
    // bottle 4 / (1 − 0.20) = 5 ; cap 1 ; label 0.45 / (1 − 0.10) = 0.5 ;
    // carton 12 per carton ÷ 12 units = 1 ; pallet 300 per pallet ÷ (25 cartons × 12 units = 300) = 1 ; total 8.5
    const r = packagingCostPerUnit({
      policy: {},
      packing: { unitsPerCarton: '12', cartonsPerPallet: '25' },
      items: [
        item({ itemId: 'bottle', price: money('4'), rejectPct: scalar('20') }),
        item({ itemId: 'cap', price: money('1') }),
        item({ itemId: 'label', price: money('0.45'), rejectPct: scalar('10') }),
        item({ itemId: 'carton', level: 'carton', price: money('12') }),
        item({ itemId: 'pallet', level: 'pallet', price: money('300') }),
      ],
    });
    expect(r.ok && r.value.lines.map((l) => l.perUnit.value)).toEqual(['5', '1', '0.5', '1', '1']);
    expect(r.ok && r.value.total.value).toBe('8.5');
  });

  it('case 2: a USD item goes through the dated rate; several items per carton', () => {
    // 0.1 USD × 50 = 5 EGP × qty 2 = 10 (USD-linked) ; dividers 3 EGP × qty 2 per carton ÷ 12 = 0.5 ; total 10.5
    const r = packagingCostPerUnit({
      policy: {}, fxRates: [usdRate], packing: { unitsPerCarton: '12' },
      items: [
        item({ itemId: 'sprayer', kind: 'pump_sprayer', price: money('0.1', 'USD'), qtyPerUnit: scalar('2') }),
        item({ itemId: 'divider', kind: 'divider', level: 'carton', price: money('3'), qtyPerUnit: scalar('2') }),
      ],
    });
    expect(r.ok && r.value.lines.map((l) => [l.key, l.perUnit.value, l.currency, l.kind])).toEqual([
      ['packaging:sprayer', '10', 'USD', 'pump_sprayer'],
      ['packaging:divider', '0.5', 'EGP', 'divider'],
    ]);
    expect(r.ok && r.value.total.value).toBe('10.5');
  });

  it('case 3: reject allowance is exact: 3 EGP at 25 % reject = 4; trace reaches price and reject sources', () => {
    // 3 / (1 − 0.25) = 4
    const r = packagingCostPerUnit({ policy: {}, items: [item({ itemId: 'jar', price: money('3'), rejectPct: scalar('25') })] });
    expect(r.ok && r.value.total.value).toBe('4');
    if (r.ok) {
      const leaves = leavesOf(r.value.total.trace);
      expect(leaves.filter((l) => l.sourceId === 'TEST-FIXTURE').map((l) => l.label)).toEqual(['jar price (EGP per item)', 'jar qty per unit', 'jar reject %']);
    }
  });

  it('missing packing configuration is reported per item, once per missing field', () => {
    const r = packagingCostPerUnit({ policy: {}, packing: { unitsPerCarton: '12' }, items: [item({ itemId: 'c', level: 'carton' }), item({ itemId: 'p', level: 'pallet' })] });
    expect(gapsOf(r)).toEqual([['p', 'cartonsPerPallet', 'packing configuration required']]);
    const none = packagingCostPerUnit({ policy: {}, items: [item({ itemId: 'c', level: 'carton' }), item({ itemId: 'p', level: 'pallet' })] });
    expect(gapsOf(none)).toEqual([
      ['c', 'unitsPerCarton', 'packing configuration required'],
      ['p', 'unitsPerCarton', 'packing configuration required'],
      ['p', 'cartonsPerPallet', 'packing configuration required'],
    ]);
  });

  it('lists EVERY gap: price, qty, reject (absent and 100), invalid packing, duplicates, FX, L7', () => {
    const r = packagingCostPerUnit({
      policy: {},
      packing: { unitsPerCarton: '0' },
      items: [
        { itemId: 'a', level: 'unit' },
        item({ itemId: 'b', rejectPct: scalar('100') }),
        item({ itemId: 'c', level: 'carton' }),
        item({ itemId: 'd', price: money('1', 'USD') }),
        item({ itemId: 'e', price: money('1', 'EGP', 'L7_estimate') }),
        item({ itemId: 'e' }),
      ],
    });
    expect(gapsOf(r)).toEqual([
      ['e', 'itemId', 'packaging.duplicate_item'],
      ['a', 'price', 'cost.price_missing'],
      ['a', 'qtyPerUnit', 'input.missing'],
      ['a', 'rejectPct', 'input.missing'],
      ['b', 'rejectPct', 'packaging.reject_out_of_range'],
      ['c', 'packing', 'packaging.invalid_packing_config'],
      ['d', 'fx:USD->EGP', 'cost.fx_rate_required'],
      ['e', 'price', 'confidence.l7_requires_signed_override'],
    ]);
  });

  it('warns on a below-policy price but still costs it; an empty BOM is an explicit zero', () => {
    const r = packagingCostPerUnit({ policy: { allowConfidenceBelow: 'L1_coa_batch' }, items: [item({ itemId: 'a', price: money('2') })] });
    expect(r.ok && r.warnings).toEqual(['confidence.below_policy:a:price:L2_supplier_tds', 'confidence.below_policy:a:qtyPerUnit:L2_supplier_tds', 'confidence.below_policy:a:rejectPct:L2_supplier_tds']);
    const empty = packagingCostPerUnit({ policy: {}, items: [] });
    expect(empty.ok && empty.value.total.value).toBe('0');
  });
});

describe('H-05 one-time costs amortized over planned quantity', () => {
  it('case 1: plate 3000 EGP / 100000 = 0.03 ; case 2: mould 20 USD × 50 / 40000 = 0.025 ; sum 0.055', () => {
    const r = amortizeOneTimeCosts(
      [
        { id: 'plate', amount: money('3000'), plannedUnits: scalar('100000') },
        { id: 'mould', description: 'Bottle mould', amount: money('20', 'USD'), plannedUnits: scalar('40000') },
      ],
      { policy: {}, fxRates: [usdRate] },
    );
    expect(r.ok && r.value.lines.map((l) => [l.key, l.label, l.perUnit.value, l.currency])).toEqual([
      ['one_time:plate', 'plate', '0.03', 'EGP'],
      ['one_time:mould', 'Bottle mould', '0.025', 'USD'],
    ]);
    expect(r.ok && r.value.total.value).toBe('0.055');
  });

  it('case 3: a bigger planned quantity lowers the share: 3000 / 300000 = 0.01', () => {
    const r = amortizeOneTimeCosts([{ id: 'plate', amount: money('3000'), plannedUnits: scalar('300000') }], { policy: {} });
    expect(r.ok && r.value.total.value).toBe('0.01');
  });

  it('missing amount, missing/zero planned units and duplicate ids are all listed', () => {
    const r = amortizeOneTimeCosts(
      [{ id: 'a', plannedUnits: scalar('10') }, { id: 'b', amount: money('5') }, { id: 'c', amount: money('5'), plannedUnits: scalar('0') }, { id: 'c', amount: money('1'), plannedUnits: scalar('1') }],
      { policy: {} },
    );
    expect(!r.ok && r.missing.map((m) => [m.materialId, m.property, m.reason])).toEqual([
      ['c', 'id', 'one_time.duplicate_id'],
      ['a', 'amount', 'cost.price_missing'],
      ['b', 'plannedUnits', 'input.missing'],
      ['c', 'plannedUnits', 'input.out_of_range'],
    ]);
  });
});

describe('H-08 packaging share of unit cost', () => {
  it('case 1: full fixture, packaging 6 of 17.585 = 34.11998...% ; bottle is 5/6 of packaging', () => {
    // 6 / 17.585 × 100 = 34.1199886267 ; one-time 0.03 / 17.585 × 100 = 0.1705999431 ;
    // container 5 (83.333...% of packaging, 5 / 17.585 = 28.4333238556 % of cost), closure 1 (16.666...%)
    const r = packagingShareReport([{ skuId: 'SKU-500', cost: fullCost() }]);
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const row = r.value[0];
    expect(row?.packagingSharePct.value.slice(0, 12)).toBe('34.119988626');
    expect(row?.oneTimeSharePct.value.slice(0, 12)).toBe('0.1705999431');
    expect(row?.byKind.map((k) => [k.kind, k.perUnit, k.sharePctOfPackaging.slice(0, 8), k.sharePctOfUnitCost.slice(0, 10)])).toEqual([
      ['container', '5', '83.33333', '28.4333238'],
      ['closure', '1', '16.66666', '5.68666477'],
    ]);
  });

  it('case 2: items without a kind are grouped as unspecified; case 3: two SKUs in input order', () => {
    // bottle without kind: 5 -> 'unspecified' 100 % of packaging
    const noKind = fullCost({ packaging: { items: [{ ...item({ itemId: 'bottle', price: money('4'), rejectPct: scalar('20') }) }] } });
    const r = packagingShareReport([{ skuId: 'A', cost: noKind }, { skuId: 'B', cost: fullCost() }]);
    expect(r.ok && r.value.map((x) => x.skuId)).toEqual(['A', 'B']);
    expect(r.ok && r.value[0]?.byKind).toEqual([{ kind: 'unspecified', perUnit: '5', sharePctOfPackaging: '100', sharePctOfUnitCost: expect.any(String) }]);
  });

  it('a zero packaging BOM gives 0 shares; zero unit cost or duplicate SKU ids are gaps', () => {
    const none = fullCost({ packaging: { items: [] } });
    const r = packagingShareReport([{ skuId: 'A', cost: none }]);
    expect(r.ok && [r.value[0]?.packagingSharePct.value, r.value[0]?.byKind]).toEqual(['0', []]);
    const zeroTotal = { ...none, total: { ...none.total, value: '0' } };
    const bad = packagingShareReport([{ skuId: 'A', cost: zeroTotal }, { skuId: 'A', cost: none }]);
    expect(!bad.ok && bad.missing.map((m) => [m.materialId, m.reason])).toEqual([['A', 'report.duplicate_sku'], ['A', 'report.unit_cost_is_zero']]);
  });
});
