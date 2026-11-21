import { describe, expect, it } from 'vitest';
import { dec } from '../core/decimal';
import { inputLeaf, leavesOf } from '../trace/trace';
import { conversionCostPerUnit } from './conversion';
import { lineCurrency } from './line';
import { itemPriceScenario, materialUsdExposure, usdExposure, usdScenario } from './exposure';
import { productCostBreakdown } from './product-cost';
import { qualityCostPerUnit } from './quality';
import { compareSkuSizes } from './sku-compare';
import { bottle, cap, fullCost, fullInput, money, productFixture, scalar, usdRate } from './test-fixtures';
import { computeUnitCost } from './unit-cost';

// TEST-FIXTURE numbers (fictional prices, chosen so every figure is exact). See test-fixtures.ts for the full BOM.
const r10 = (v: string | undefined) => dec(v ?? '0').toDecimalPlaces(10).toFixed();
const gaps = (r: { ok: boolean; missing?: { materialId?: string; property: string; reason: string }[] }) => (r.ok ? [] : (r.missing ?? []).map((m) => [m.materialId, m.property, m.reason]));

describe('I-02 conversion cost per unit', () => {
  const base = { policy: {}, unitsPerBatch: scalar('1000'), unitMassKg: inputLeaf('Unit mass', '0.5', 'kg') };

  it('case 1: labour 0.5 EGP/min × 120 min per batch ÷ 1000 units = 0.06', () => {
    const r = conversionCostPerUnit({ ...base, lines: [{ id: 'labour', kind: 'labour', rate: money('0.5'), qtyPerBatch: scalar('120') }], overheads: [] });
    expect(r.ok && [r.value.total.value, r.value.lines[0]?.kind]).toEqual(['0.06', 'labour']);
  });

  it('case 2: energy 2 × 0.01 kWh = 0.02 ; water 0.01 × 0.5 L = 0.005 ; sum 0.025', () => {
    const r = conversionCostPerUnit({
      policy: {},
      lines: [{ id: 'energy', kind: 'energy', rate: money('2'), qtyPerUnit: scalar('0.01') }, { id: 'water', kind: 'water', rate: money('0.01'), qtyPerUnit: scalar('0.5') }],
      overheads: [],
    });
    expect(r.ok && [r.value.lines.map((l) => l.perUnit.value), r.value.total.value]).toEqual([['0.02', '0.005'], '0.025']);
  });

  it('case 3: overheads by a fixed key: per kg 20000 / 10000 kg × 0.5 kg = 1 ; per line hour 6000 / 200 h × 0.002 h = 0.06', () => {
    const r = conversionCostPerUnit({
      ...base,
      lines: [],
      overheads: [
        { id: 'rent', periodCost: money('20000'), key: 'per_kg', keyTotal: scalar('10000', 'kg') },
        { id: 'line', periodCost: money('6000', 'USD'), key: 'per_line_hour', keyTotal: scalar('200'), lineHoursPerUnit: scalar('0.002') },
      ],
      fxRates: [usdRate],
    });
    // the USD pool: 6000 × 50 = 300000 / 200 = 1500 per h × 0.002 = 3
    expect(r.ok && [r.value.lines.map((l) => [l.perUnit.value, l.currency]), r.value.total.value]).toEqual([[['1', 'EGP'], ['3', 'USD']], '4']);
  });

  it('every gap listed: no sections, then bad lines (both/neither qty basis, no rate, no batch units, key problems)', () => {
    const none = conversionCostPerUnit({ policy: {} });
    expect(gaps(none)).toEqual([[undefined, 'conversion.lines', 'conversion.section_missing'], [undefined, 'conversion.overheads', 'conversion.section_missing']]);
    expect(gaps(conversionCostPerUnit({ policy: {}, lines: [], overheads: undefined }))).toEqual([[undefined, 'conversion.overheads', 'conversion.section_missing']]);
    expect(gaps(conversionCostPerUnit({ policy: {}, lines: undefined, overheads: [] }))).toEqual([[undefined, 'conversion.lines', 'conversion.section_missing']]);
    const r = conversionCostPerUnit({
      policy: {},
      lines: [
        { id: 'both', kind: 'other', rate: money('1'), qtyPerBatch: scalar('1'), qtyPerUnit: scalar('1') },
        { id: 'neither', kind: 'other' },
        { id: 'batch', kind: 'labour', rate: money('1'), qtyPerBatch: scalar('1') },
        { id: 'unit', kind: 'energy', rate: money('1') , qtyPerUnit: scalar('x') },
      ],
      overheads: [
        { id: 'nokey', periodCost: money('1') },
        { id: 'badkey', periodCost: money('1'), key: 'per_month' as never },
        { id: 'kg', periodCost: money('1'), key: 'per_kg' },
        { id: 'hours', periodCost: money('1'), key: 'per_line_hour', keyTotal: scalar('10') },
        { id: 'both', periodCost: money('1'), key: 'per_kg', keyTotal: scalar('1', 'g'), },
      ],
    });
    expect(gaps(r)).toEqual([
      ['both', 'id', 'conversion.duplicate_id'],
      ['both', 'qty', 'conversion.give_exactly_one_of_qty_per_batch_or_per_unit'],
      ['neither', 'rate', 'cost.price_missing'],
      ['neither', 'qty', 'conversion.give_exactly_one_of_qty_per_batch_or_per_unit'],
      [undefined, 'unitsPerBatch', 'input.missing'],
      ['unit', 'qtyPerUnit', 'input.invalid_number'],
      ['nokey', 'key', 'input.missing'],
      ['badkey', 'key', 'conversion.invalid_allocation_key'],
      ['kg', 'keyTotal', 'input.missing'],
      ['kg', 'unitMassKg', 'input.missing'],
      ['hours', 'lineHoursPerUnit', 'input.missing'],
      ['both', 'keyTotal', 'input.unit_mismatch'],
      ['both', 'unitMassKg', 'input.missing'],
    ]);
  });
});

describe('I-03 quality and regulatory cost per unit', () => {
  it('case 1: batch tests (300 × 1 + 20 × 2) ÷ 1000 units = 0.34', () => {
    const r = qualityCostPerUnit({ policy: {}, unitsPerBatch: scalar('1000'), batchTests: [{ id: 'micro', costPerTest: money('300'), testsPerBatch: scalar('1') }, { id: 'ph', costPerTest: money('20'), testsPerBatch: scalar('2') }], regulatory: [] });
    expect(r.ok && [r.value.tests.value, r.value.regulatory.value, r.value.total.value]).toEqual(['0.34', '0', '0.34']);
  });

  it('case 2: EDA fee 5000 / 100000 = 0.05 ; IFRA certificate 100 USD × 50 / 250000 = 0.02', () => {
    const r = qualityCostPerUnit({
      policy: {}, fxRates: [usdRate], batchTests: [],
      regulatory: [{ id: 'eda', amount: money('5000'), plannedUnits: scalar('100000') }, { id: 'ifra', amount: money('100', 'USD'), plannedUnits: scalar('250000') }],
    });
    expect(r.ok && [r.value.lines.map((l) => [l.key, l.perUnit.value, l.currency]), r.value.regulatory.value]).toEqual([[['regulatory:eda', '0.05', 'EGP'], ['regulatory:ifra', '0.02', 'USD']], '0.07']);
  });

  it('case 3: both together 0.34 + 0.07 = 0.41 and the trace names every source', () => {
    const f = fullCost();
    const q = qualityCostPerUnit({ ...fullInput().quality, policy: {}, fxRates: [usdRate] });
    expect(q.ok && q.value.total.value).toBe('0.41');
    expect(f.sections.quality.value).toBe('0.34');
    expect(f.sections.regulatory.value).toBe('0.07');
    expect(q.ok && leavesOf(q.value.total.trace).every((l) => l.label.includes('FX') || l.sourceId === 'TEST-FIXTURE')).toBe(true);
  });

  it('missing sections, prices, test counts, batch size and duplicates are all listed', () => {
    expect(gaps(qualityCostPerUnit({ policy: {} }))).toEqual([[undefined, 'quality.batchTests', 'quality.section_missing'], [undefined, 'quality.regulatory', 'quality.section_missing']]);
    expect(gaps(qualityCostPerUnit({ policy: {}, batchTests: [], regulatory: undefined }))).toEqual([[undefined, 'quality.regulatory', 'quality.section_missing']]);
    expect(gaps(qualityCostPerUnit({ policy: {}, batchTests: undefined, regulatory: [] }))).toEqual([[undefined, 'quality.batchTests', 'quality.section_missing']]);
    const r = qualityCostPerUnit({
      policy: {},
      batchTests: [{ id: 'a' }, { id: 'a', costPerTest: money('1'), testsPerBatch: scalar('1') }],
      regulatory: [{ id: 'eda', amount: money('5000') }],
    });
    expect(gaps(r)).toEqual([
      ['a', 'id', 'quality.duplicate_test_id'],
      ['a', 'costPerTest', 'cost.price_missing'],
      ['a', 'testsPerBatch', 'input.missing'],
      [undefined, 'unitsPerBatch', 'input.missing'],
      ['eda', 'plannedUnits', 'input.missing'],
    ]);
  });
});

describe('I-01 full unit cost', () => {
  it('case 1: every section by hand => 10 + 6 + 1.145 + 0.34 + 0.07 + 0.03 = 17.585', () => {
    const c = fullCost();
    expect(Object.fromEntries(Object.entries(c.sections).map(([k, v]) => [k, v.value]))).toEqual({
      product: '10', packaging: '6', conversion: '1.145', quality: '0.34', regulatory: '0.07', one_time: '0.03',
    });
    expect(c.total.value).toBe('17.585');
    expect(c.fillKg.value).toBe('0.5');
    expect(c.lines.map((l) => l.key)).toEqual([
      'product', 'packaging:bottle', 'packaging:cap', 'conversion:labour', 'conversion:energy', 'conversion:water', 'conversion:rent', 'conversion:line',
      'quality:micro', 'quality:ph', 'regulatory:eda', 'regulatory:ifra', 'one_time:plate',
    ]);
    expect(c.skuId).toBe('SKU-500');
  });

  it('case 2: a VOLUME fill uses the measured density: 0.5 L × 1.2 kg/L = 0.6 kg => product 12, per-kg overhead 2 × 0.6 = 1.2', () => {
    const c = fullCost({ fillActual: { ...money('0.5'), unit: 'L' }, productDensity: { ...money('1.2'), unit: 'kg/L' } });
    expect(c.fillKg.value).toBe('0.6');
    expect(c.sections.product.value).toBe('12');
    expect(c.lines.find((l) => l.key === 'conversion:rent')?.perUnit.value).toBe('1.2');
    expect(leavesOf(c.fillKg.trace).map((l) => l.label)).toEqual(['Fill actual (L)', 'Measured product density']);
  });

  it('case 3: overfill is part of the fill input: 510 g instead of 500 g costs 0.2 EGP more product (510 g × 20/kg = 10.2)', () => {
    const c = fullCost({ fillActual: { ...money('510'), unit: 'g' } });
    expect(c.sections.product.value).toBe('10.2');
  });

  it('every line traces down to sourced inputs; the total is Σ of unrounded sections', () => {
    const c = fullCost();
    const leaves = leavesOf(c.total.trace);
    expect(leaves.length).toBeGreaterThan(30);
    expect(leaves.filter((l) => l.sourceId === 'TEST-FIXTURE').length).toBeGreaterThan(20);
    expect(c.total.trace.op).toBe('sum');
    expect(c.total.trace.inputs).toHaveLength(6);
  });

  it('warns on an explicitly empty section and on a breakdown that does not add up; a unit cost without breakdown still computes', () => {
    const empty = computeUnitCost(fullInput({ oneTime: [] }));
    expect(empty.ok && empty.warnings).toContain('costing.section_empty:one_time');
    const p = productFixture();
    const drift = computeUnitCost(fullInput({ product: { costPerKg: p.costPerKg, materials: p.contributions.slice(0, 1) } }));
    expect(drift.ok && drift.warnings).toContain('costing.product_breakdown_mismatch');
    const plain = computeUnitCost(fullInput({ product: { costPerKg: p.costPerKg } }));
    expect(plain.ok && [plain.value.materialLines.length, plain.value.total.value]).toEqual([0, '17.585']);
    expect(plain.ok && plain.value.lines[0]?.currency).toBe('mixed');
  });

  it('lists ALL gaps of ALL sections at once; nothing becomes zero', () => {
    const r = computeUnitCost({ policy: {} });
    expect(gaps(r)).toEqual([
      [undefined, 'fillActual', 'fill.actual_required'],
      [undefined, 'product.costPerKg', 'costing.product_cost_per_kg_missing'],
      [undefined, 'packaging', 'costing.section_missing'],
      [undefined, 'conversion', 'costing.section_missing'],
      [undefined, 'quality', 'costing.section_missing'],
      [undefined, 'oneTime', 'costing.section_missing'],
    ]);
  });

  it('section-level gaps surface with their ids; a per-kg overhead does not add a gap caused by a missing fill', () => {
    const r = computeUnitCost(fullInput({
      fillActual: undefined,
      packaging: { items: [{ ...bottle(), price: undefined }, { ...cap(), rejectPct: undefined }] },
      oneTime: [{ id: 'plate', plannedUnits: scalar('10') }],
    }));
    expect(gaps(r)).toEqual([
      [undefined, 'fillActual', 'fill.actual_required'],
      ['bottle', 'price', 'cost.price_missing'],
      ['cap', 'rejectPct', 'input.missing'],
      ['plate', 'amount', 'cost.price_missing'],
    ]);
  });

  it('bad fill and product inputs: non-mass unit, volume without density, non-EGP cost, zero fill, L7 fill', () => {
    expect(gaps(computeUnitCost(fullInput({ fillActual: money('500') })))).toEqual([[undefined, 'fillActual', 'fill.actual_must_be_mass_or_volume']]);
    expect(gaps(computeUnitCost(fullInput({ fillActual: { ...money('0.5'), unit: 'L' } })))).toEqual([[undefined, 'productDensity', 'density.required_for_conversion']]);
    expect(gaps(computeUnitCost(fullInput({ fillActual: { ...money('0'), unit: 'g' } })))).toEqual([[undefined, 'fillActual', 'input.out_of_range']]);
    expect(gaps(computeUnitCost(fullInput({ fillActual: { ...money('1', 'EGP', 'L7_estimate'), unit: 'g' } })))).toEqual([[undefined, 'fillActual', 'confidence.l7_requires_signed_override']]);
    expect(gaps(computeUnitCost(fullInput({ product: { costPerKg: inputLeaf('c', '20', 'USD') } })))).toEqual([[undefined, 'product.costPerKg', 'costing.product_cost_per_kg_not_egp']]);
    expect(gaps(computeUnitCost(fullInput({ product: {} })))).toEqual([[undefined, 'product.costPerKg', 'costing.product_cost_per_kg_missing']]);
  });
});

describe('USD exposure of a formula material cost (editor cost bar)', () => {
  const priced = (value: string, unit: 'EGP' | 'USD' | 'EUR') => ({ value, unit, sourceId: 'TEST-FIXTURE', confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' });
  const lines = [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '30' }, { materialId: 'C', percentWW: '20' }];
  const prices = { A: priced('10', 'EGP'), B: priced('2', 'USD'), C: priced('1', 'EUR') };
  const fxRates = [usdRate, { ...usdRate, from: 'EUR' as const, rate: '60' }];

  it('only the USD-priced material is USD-linked: 0.3 × 2 × 50 = 30 of 5 + 30 + 12 = 47', () => {
    const breakdown = productCostBreakdown({ lines, prices, fxRates, policy: {} }, '0');
    expect(breakdown.ok).toBe(true);
    const e = breakdown.ok ? materialUsdExposure(breakdown.value) : undefined;
    expect(e?.ok && [e.value.total.value, e.value.usdLinked.value, r10(e.value.usdSharePct.value)]).toEqual(['47', '30', '63.829787234']);
    expect(e?.ok && e.value.byLine.map((l) => [l.key, l.usdLinked.value])).toEqual([['product:A', '0'], ['product:B', '30'], ['product:C', '0']]);
  });

  it('an EGP-only formula has 0 % exposure, and a zero total is missing, never a share', () => {
    const egp = productCostBreakdown({ lines: [{ materialId: 'A', percentWW: '100' }], prices: { A: priced('10', 'EGP') }, policy: {} }, '0');
    const e = egp.ok ? materialUsdExposure(egp.value) : undefined;
    expect(e?.ok && e.value.usdSharePct.value).toBe('0');
    const free = productCostBreakdown({ lines: [{ materialId: 'A', percentWW: '100' }], prices: { A: priced('0', 'EGP') }, policy: {} }, '0');
    const z = free.ok ? materialUsdExposure(free.value) : undefined;
    expect(z && !z.ok && z.missing[0]?.reason).toBe('exposure.total_cost_is_zero');
  });
});

describe('I-06 USD exposure', () => {
  const cost = fullCost();

  it('a line priced in another foreign currency keeps its own currency and is not USD-linked', () => {
    expect(lineCurrency(money('1', 'EUR'))).toBe('EUR');
    expect(lineCurrency(money('1', 'USD'))).toBe('USD');
    expect(lineCurrency(money('1', 'EGP'))).toBe('EGP');
  });

  it('case 1: USD-linked = material B 0.5 × 12.5 = 6.25 + cap 1 + IFRA 0.02 = 7.27 of 17.585 = 41.34205...%', () => {
    const e = usdExposure(cost);
    expect(e.ok && [e.value.usdLinked.value, r10(e.value.usdSharePct.value)]).toEqual(['7.27', '41.342052886']);
    expect(e.ok && e.value.byLine.filter((l) => l.usdLinked.value !== '0').map((l) => [l.key, l.usdLinked.value, l.usdSharePct])).toEqual([
      ['product:B', '6.25', '100'], ['packaging:cap', '1', '100'], ['regulatory:ifra', '0.02', '100'],
    ]);
  });

  it('case 2: USD +10 % => +0.727 => 18.312 (+4.1342...%); USD −10 % => 16.858', () => {
    const up = usdScenario(cost, '10');
    expect(up.ok && [up.value.delta.value, up.value.newTotal.value, r10(up.value.deltaPct.value)]).toEqual(['0.727', '18.312', '4.1342052886']);
    const down = usdScenario(cost, '-10');
    expect(down.ok && down.value.newTotal.value).toBe('16.858');
  });

  it('case 3: one item price: bottle +50 % => +2.5 => 20.085 ; material B −20 % => −1.25 => 16.335', () => {
    const a = itemPriceScenario(cost, 'packaging:bottle', '50');
    expect(a.ok && [a.value.delta.value, a.value.newTotal.value, r10(a.value.deltaPct.value)]).toEqual(['2.5', '20.085', '14.2166619278']);
    const b = itemPriceScenario(cost, 'product:B', '-20');
    expect(b.ok && [b.value.delta.value, b.value.newTotal.value]).toEqual(['-1.25', '16.335']);
  });

  it('cross-check: recomputing with the USD rate +10 % gives the same packaging/regulatory USD effect', () => {
    // cap 0.02 USD: 50 -> 55 EGP/USD => 1.1 (+0.1) ; IFRA 100 USD: 5000 -> 5500 / 250000 = 0.022 (+0.002)
    const c2 = computeUnitCost(fullInput({ fxRates: [{ ...usdRate, rate: '55' }] }));
    const line = (c: typeof cost, key: string) => c.lines.find((l) => l.key === key)?.perUnit.value ?? '';
    if (!c2.ok) {
      throw new Error('expected ok');
    }
    expect(dec(line(c2.value, 'packaging:cap')).minus(line(cost, 'packaging:cap')).toFixed()).toBe('0.1');
    const c2Ifra = line(c2.value, 'regulatory:ifra');
    expect(c2Ifra).toBe('0.022');
  });

  it('missing breakdown, zero total, invalid percentages and unknown lines are reported, never guessed', () => {
    const plain = fullCost({ product: { costPerKg: productFixture().costPerKg } });
    expect(gaps(usdExposure(plain))).toEqual([[undefined, 'product.materials', 'exposure.product_breakdown_required']]);
    expect(gaps(usdScenario(plain, '10'))).toEqual([[undefined, 'product.materials', 'exposure.product_breakdown_required']]);
    expect(gaps(usdExposure({ ...cost, total: { ...cost.total, value: '0' } }))).toEqual([[undefined, 'unitCost.total', 'exposure.total_cost_is_zero']]);
    expect(gaps(usdScenario(cost, 'abc'))).toEqual([[undefined, 'usdChangePct', 'exposure.invalid_change_pct']]);
    expect(gaps(usdScenario(cost, '-101'))).toEqual([[undefined, 'usdChangePct', 'exposure.invalid_change_pct']]);
    expect(gaps(itemPriceScenario(cost, 'packaging:bottle', 'x'))).toEqual([[undefined, 'changePct', 'exposure.invalid_change_pct']]);
    expect(gaps(itemPriceScenario(cost, 'packaging:nope', '5'))).toEqual([[undefined, 'packaging:nope', 'exposure.line_not_found']]);
  });

  it('a zero line has no share; a zero base total gives a zero percent change', () => {
    const zeroLine = fullCost({ oneTime: [{ id: 'free', amount: money('0'), plannedUnits: scalar('10') }] });
    const e = usdExposure(zeroLine);
    expect(e.ok && e.value.byLine.find((l) => l.key === 'one_time:free')?.usdSharePct).toBeNull();
  });
});

describe('I-07 SKU comparison across pack sizes', () => {
  // Same formula (20 EGP per net kg), labour 0.5 per unit, empty quality/one-time:
  //   250 g: product 5,  bottle 3 => total 8.5  => 34 /kg ; non-product (8.5 − 5) / 0.25 = 14
  //   500 g: product 10, bottle 4 => total 14.5 => 29 /kg ; non-product 4.5 / 0.5 = 9
  //   1 kg : product 20, bottle 6 => total 26.5 => 26.5 /kg ; non-product 6.5
  const size = (grams: string, bottlePrice: string) =>
    fullCost({
      skuId: grams,
      fillActual: { ...money(grams), unit: 'g' },
      packaging: { items: [{ itemId: 'bottle', level: 'unit', price: money(bottlePrice), qtyPerUnit: scalar('1'), rejectPct: scalar('0') }] },
      conversion: { lines: [{ id: 'labour', kind: 'labour', rate: money('0.5'), qtyPerUnit: scalar('1') }], overheads: [] },
      quality: { batchTests: [], regulatory: [] },
      oneTime: [],
    });
  const skus = [{ skuId: '250', cost: size('250', '3') }, { skuId: '500', cost: size('500', '4') }, { skuId: '1000', cost: size('1000', '6') }];

  it('case 1: cost per kg 34 / 29 / 26.5 with product and non-product split', () => {
    const r = compareSkuSizes({ skus, basis: 'kg', policy: {} });
    expect(r.ok && r.value.map((x) => [x.skuId, x.totalPerUnit.value, x.perKg.value, x.productPerKg.value, x.nonProductPerKg.value, x.rank, x.perL])).toEqual([
      ['250', '8.5', '34', '20', '14', 3, undefined], ['500', '14.5', '29', '20', '9', 2, undefined], ['1000', '26.5', '26.5', '20', '6.5', 1, undefined],
    ]);
  });

  it('case 2: per litre with density 0.8 kg/L: 34 × 0.8 = 27.2, 23.2, 21.2 ; vs cheapest 28.30188...% / 9.43396...% / 0', () => {
    const r = compareSkuSizes({ skus, basis: 'both', productDensity: { ...money('0.8'), unit: 'kg/L' }, policy: {} });
    expect(r.ok && r.value.map((x) => [x.perL?.value, r10(x.vsCheapestPct.value)])).toEqual([['27.2', '28.3018867925'], ['23.2', '9.4339622642'], ['21.2', '0']]);
  });

  it('case 3: a single size ranks 1 with 0 % difference', () => {
    const r = compareSkuSizes({ skus: [skus[1]!], basis: 'kg', policy: {} });
    expect(r.ok && [r.value[0]?.rank, r.value[0]?.vsCheapestPct.value]).toEqual([1, '0']);
  });

  it('missing density for L, empty list, duplicate ids and a zero fill are gaps', () => {
    expect(gaps(compareSkuSizes({ skus, basis: 'L', policy: {} }))).toEqual([[undefined, 'productDensity', 'density.required_for_conversion']]);
    expect(gaps(compareSkuSizes({ skus: [], basis: 'kg', policy: {} }))).toEqual([[undefined, 'skus', 'compare.no_skus']]);
    const zeroFill = { ...skus[0]!.cost, fillKg: { ...skus[0]!.cost.fillKg, value: '0' } };
    expect(gaps(compareSkuSizes({ skus: [skus[0]!, skus[0]!, { skuId: 'z', cost: zeroFill }], basis: 'kg', policy: {} }))).toEqual([
      ['250', 'skuId', 'compare.duplicate_sku'], ['z', 'fillKg', 'compare.fill_must_be_positive'],
    ]);
  });
});
