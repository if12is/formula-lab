import type { Confidence, SourcedValue } from '../contract';
import type { FxRate } from '../core/fx';
import type { SourcedScalar } from '../core/parse';
import type { PackagingBomItem } from '../packaging/bom';
import { productCostBreakdown } from './product-cost';
import { computeUnitCost, type UnitCost, type UnitCostInput } from './unit-cost';

/**
 * TEST INPUTS ONLY (sourceId 'TEST-FIXTURE'). Fictional prices chosen so every figure is exact by hand;
 * never imported by production code.
 */
export const money = (value: string, unit: 'EGP' | 'USD' = 'EGP', confidence: Confidence = 'L2_supplier_tds'): SourcedValue => ({
  value,
  unit,
  sourceId: 'TEST-FIXTURE',
  confidence,
  validFrom: '2026-01-01',
});

export const scalar = (value: string, unit?: SourcedScalar['unit'], confidence: Confidence = 'L2_supplier_tds'): SourcedScalar => ({
  value,
  ...(unit !== undefined && { unit }),
  sourceId: 'TEST-FIXTURE',
  confidence,
  validFrom: '2026-01-01',
});

export const usdRate: FxRate = { from: 'USD', to: 'EGP', rate: '50', date: '2026-02-01', sourceId: 'FX-TEST', source: 'TEST' };

/** Bottle 4 EGP, 20 % reject => 5; cap 0.02 USD x 50 => 1. */
export const bottle = (): PackagingBomItem => ({ itemId: 'bottle', kind: 'container', level: 'unit', price: money('4'), qtyPerUnit: scalar('1'), rejectPct: scalar('20') });
export const cap = (): PackagingBomItem => ({ itemId: 'cap', kind: 'closure', level: 'unit', price: money('0.02', 'USD'), qtyPerUnit: scalar('1'), rejectPct: scalar('0') });

/**
 * Materials: A 60 % at 10 EGP/kg, B 40 % at 0.5 USD/kg (x 50 = 25) => 16 EGP/kg; wastage 20 % => 20 EGP per net kg.
 * Contributions per net kg: A 7.5 (EGP), B 12.5 (USD).
 */
export function productFixture() {
  const r = productCostBreakdown(
    {
      lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }],
      prices: { A: money('10'), B: money('0.5', 'USD') },
      fxRates: [usdRate],
      policy: {},
    },
    '20',
  );
  if (!r.ok) {
    throw new Error('fixture product cost must be ok');
  }
  return r.value;
}

/**
 * Full fixture, fill 500 g (0.5 kg):
 *   product 0.5 x 20 = 10 | packaging 5 + 1 = 6 |
 *   conversion labour 0.5 x 120 / 1000 = 0.06, energy 2 x 0.01 = 0.02, water 0.01 x 0.5 = 0.005,
 *              overhead per kg 20000 / 10000 x 0.5 = 1, per line hour 6000 / 200 x 0.002 = 0.06  => 1.145 |
 *   quality tests (300 x 1 + 20 x 2) / 1000 = 0.34 | regulatory 5000 / 100000 + 5000 (100 USD) / 250000 = 0.07 |
 *   one-time 3000 / 100000 = 0.03                                                   => total 17.585
 */
export function fullInput(overrides: Partial<UnitCostInput> = {}): UnitCostInput {
  const product = productFixture();
  return {
    skuId: 'SKU-500',
    policy: {},
    fxRates: [usdRate],
    product: { costPerKg: product.costPerKg, materials: product.contributions },
    fillActual: { ...money('500'), unit: 'g' },
    packaging: { items: [bottle(), cap()] },
    conversion: {
      unitsPerBatch: scalar('1000'),
      lines: [
        { id: 'labour', kind: 'labour', rate: money('0.5'), qtyPerBatch: scalar('120') },
        { id: 'energy', kind: 'energy', rate: money('2'), qtyPerUnit: scalar('0.01') },
        { id: 'water', kind: 'water', rate: money('0.01'), qtyPerUnit: scalar('0.5') },
      ],
      overheads: [
        { id: 'rent', periodCost: money('20000'), key: 'per_kg', keyTotal: scalar('10000', 'kg') },
        { id: 'line', periodCost: money('6000'), key: 'per_line_hour', keyTotal: scalar('200'), lineHoursPerUnit: scalar('0.002') },
      ],
    },
    quality: {
      unitsPerBatch: scalar('1000'),
      batchTests: [
        { id: 'micro', costPerTest: money('300'), testsPerBatch: scalar('1') },
        { id: 'ph', costPerTest: money('20'), testsPerBatch: scalar('2') },
      ],
      regulatory: [
        { id: 'eda', amount: money('5000'), plannedUnits: scalar('100000') },
        { id: 'ifra', amount: money('100', 'USD'), plannedUnits: scalar('250000') },
      ],
    },
    oneTime: [{ id: 'plate', description: 'Printing plate', amount: money('3000'), plannedUnits: scalar('100000') }],
    ...overrides,
  };
}

export function fullCost(overrides: Partial<UnitCostInput> = {}): UnitCost {
  const r = computeUnitCost(fullInput(overrides));
  if (!r.ok) {
    throw new Error(`fixture unit cost must be ok: ${JSON.stringify(r.missing)}`);
  }
  return r.value;
}
