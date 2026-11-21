/**
 * F-02 acceptance: 20 hand-calculated cost cases. All materials and prices are FICTIONAL
 * (MAT-A at 10.00 EGP/kg, MAT-B at 25.50 EGP/kg, ...), never real market prices.
 * Each case shows its arithmetic in the comment above it; expected values are the hand results.
 *
 * Formulas (plan/00-research/04-deterministic-formulas.md):
 *   C_kg = Σ p/100 × P          M_gross = M / (1 − w/100)       perKg = C_kg / (1 − w/100)
 *   perL = perKg × ρ            perPack = m_pack × perKg + C_pack
 *   N = floor(M_net / pack)     C_batch = C_kg × M_gross + N × C_pack
 *   Price = C_unit / (1 − margin/100)
 */
import { describe, expect, it } from 'vitest';
import { calculate } from '../../src/calculate';
import { ENGINE_VERSION, type Confidence, type EngineInput, type SourcedValue } from '../../src/contract';
import type { Unit } from '../../src/core/quantity';

type Line = [materialId: string, percent: string, price: string, extra?: { unit?: Unit; confidence?: Confidence }];

interface Case {
  name: string;
  lines: Line[];
  batch?: { size: [string, Unit]; wastage: string; pack: [string, Unit]; packCost: string };
  density?: string;
  margin?: string;
  fx?: string;
  floor?: Confidence;
  overrides?: NonNullable<EngineInput['policy']['overrides']>;
  expected: Record<string, string>;
  absent?: string[];
  missing?: string[];
  warnings?: string[];
}

const sv = (value: string, unit: Unit, confidence: Confidence, id: string): SourcedValue => ({
  value, unit, sourceId: id, confidence, validFrom: '2026-01-01',
});

function inputOf(c: Case): EngineInput {
  const prices: EngineInput['prices'] = {};
  for (const [id, , price, extra] of c.lines) {
    prices[id] = sv(price, extra?.unit ?? 'EGP', extra?.confidence ?? 'L2_supplier_tds', `SRC-${id}`);
  }
  return {
    engineVersion: ENGINE_VERSION,
    datasetVersion: 'golden-v1',
    formula: {
      lines: c.lines.map(([materialId, percentWW]) => ({ materialId, percentWW })),
      ...(c.density === undefined ? {} : { productDensity: sv(c.density, 'kg/L', 'L1_coa_batch', 'LAB-RHO') }),
    },
    ...(c.batch === undefined
      ? {}
      : {
          batch: {
            size: { value: c.batch.size[0], unit: c.batch.size[1] },
            wastagePct: c.batch.wastage,
            packSize: { value: c.batch.pack[0], unit: c.batch.pack[1] },
          },
          packaging: { perPack: sv(c.batch.packCost, 'EGP', 'L2_supplier_tds', 'SRC-PACK') },
        }),
    prices,
    policy: { rounding: 'default', allowConfidenceBelow: c.floor, overrides: c.overrides },
    ...(c.fx === undefined ? {} : { fxRates: [{ from: 'USD', to: 'EGP', rate: c.fx, date: '2026-02-01', sourceId: 'FX-FICTIONAL', source: 'TEST' }] }),
    ...(c.margin === undefined ? {} : { pricing: { marginPct: c.margin } }),
  };
}

const A: Line = ['MAT-A', '60', '10.00'];
const B: Line = ['MAT-B', '40', '25.50'];

const cases: Case[] = [
  {
    // 1. single material: 1.00 × 10.00 = 10
    name: '01 single material',
    lines: [['MAT-A', '100', '10.00']],
    expected: { 'cost.materialPerKg': '10' },
  },
  {
    // 2. 0.60 × 10.00 + 0.40 × 25.50 = 6 + 10.2 = 16.2
    name: '02 two materials',
    lines: [A, B],
    expected: { 'cost.materialPerKg': '16.2' },
  },
  {
    // 3. 0.50×10.00 + 0.30×25.50 + 0.20×8.25 = 5 + 7.65 + 1.65 = 14.3
    name: '03 three materials',
    lines: [['MAT-A', '50', '10.00'], ['MAT-B', '30', '25.50'], ['MAT-C', '20', '8.25']],
    expected: { 'cost.materialPerKg': '14.3' },
  },
  {
    // 4. 0.70×0.40 + 0.25×10.00 + 0.05×25.50 = 0.28 + 2.5 + 1.275 = 4.055
    name: '04 water-heavy formula',
    lines: [['MAT-W', '70', '0.40'], ['MAT-A', '25', '10.00'], ['MAT-B', '5', '25.50']],
    expected: { 'cost.materialPerKg': '4.055' },
  },
  {
    // 5. C_kg = 16.2; net 100 kg, w = 20%: M_gross = 100/0.8 = 125; perKg = 16.2/0.8 = 20.25
    //    perL (ρ 1) = 20.25; N = 100000 g / 500 g = 200, rem 0; materials = 16.2×125 = 2025
    //    packaging = 200 × 2 = 400; batch = 2425; perPack = 0.5×20.25 + 2 = 12.125
    name: '05 wastage 20% with packs',
    lines: [A, B],
    density: '1.00',
    batch: { size: ['100', 'kg'], wastage: '20', pack: ['500', 'g'], packCost: '2.00' },
    expected: {
      'batch.grossMass': '125', 'cost.perKg': '20.25', 'cost.perL': '20.25', 'batch.fullPacks': '200',
      'batch.remainder': '0', 'cost.materialBatch': '2025', 'cost.packagingBatch': '400', 'cost.batch': '2425',
      'cost.perPack': '12.125',
    },
  },
  {
    // 6. C_kg = 16.2, w = 0, ρ = 1.02; net 50 L = 51 kg; pack 500 mL: N = 50000/500 = 100, rem 0
    //    perL = 16.2×1.02 = 16.524; pack mass = 0.5 L × 1.02 = 0.51 kg; perPack = 0.51×16.2 + 3 = 8.262 + 3 = 11.262
    //    materials = 16.2 × 51 = 826.2; packaging = 100×3 = 300; batch = 1126.2
    name: '06 litre batch with measured density',
    lines: [A, B],
    density: '1.02',
    batch: { size: ['50', 'L'], wastage: '0', pack: ['500', 'mL'], packCost: '3.00' },
    expected: {
      'batch.netMass': '51', 'batch.fullPacks': '100', 'cost.perL': '16.524', 'batch.packMass': '0.51',
      'cost.perPack': '11.262', 'cost.materialBatch': '826.2', 'cost.packagingBatch': '300', 'cost.batch': '1126.2',
    },
  },
  {
    // 7. 10 L, 300 mL pack, ρ 1.00: N = floor(10000/300) = floor(33.33) = 33; rem = 10000 − 33×300 = 100 mL (no ceil)
    //    C_kg = 10; packMass 0.3 kg; perPack = 3 + 1.5 = 4.5; materials = 10 × 10 = 100; packaging = 33 × 1.5 = 49.5; batch = 149.5
    name: '07 floor and remainder',
    lines: [['MAT-A', '100', '10.00']],
    density: '1.00',
    batch: { size: ['10', 'L'], wastage: '0', pack: ['300', 'mL'], packCost: '1.50' },
    expected: {
      'batch.fullPacks': '33', 'batch.remainder': '100', 'cost.perPack': '4.5',
      'cost.packagingBatch': '49.5', 'cost.materialBatch': '100', 'cost.batch': '149.5',
    },
  },
  {
    // 8. kg batch, gram pack, NO density: 40 kg, 250 g pack: N = 40000/250 = 160, rem 0
    //    perKg = 10; perPack = 0.25×10 + 1 = 3.5; materials = 10×40 = 400; packaging = 160; batch = 560
    //    per-L needs ρ => absent and productDensity reported missing
    name: '08 kg-only without density',
    lines: [['MAT-A', '100', '10.00']],
    batch: { size: ['40', 'kg'], wastage: '0', pack: ['250', 'g'], packCost: '1.00' },
    expected: { 'batch.fullPacks': '160', 'batch.remainder': '0', 'cost.perKg': '10', 'cost.perPack': '3.5', 'cost.batch': '560' },
    absent: ['cost.perL', 'batch.netVolume'],
    missing: ['productDensity'],
  },
  {
    // 9. kg batch but mL pack, no density: pack count and per-pack cost cannot be computed; per-kg still is.
    //    perKg = 16.2 (w = 0); gross = 40
    name: '09 volume pack without density',
    lines: [A, B],
    batch: { size: ['40', 'kg'], wastage: '0', pack: ['500', 'mL'], packCost: '1.00' },
    expected: { 'cost.perKg': '16.2', 'batch.grossMass': '40' },
    absent: ['cost.perL', 'cost.perPack', 'batch.fullPacks', 'cost.batch'],
    missing: ['productDensity'],
  },
  {
    // 10. margin 20%: 16.2 / (1 − 0.20) = 16.2 / 0.8 = 20.25 (perKg = perL = perPack since ρ = 1, pack = 1 kg, no pack cost)
    name: '10 margin 20%',
    lines: [A, B],
    density: '1.00',
    margin: '20',
    batch: { size: ['10', 'kg'], wastage: '0', pack: ['1', 'kg'], packCost: '0.00' },
    expected: { 'price.perKg': '20.25', 'price.perL': '20.25', 'price.perPack': '20.25' },
  },
  {
    // 11. margin 50%: 16.2 / 0.5 = 32.4
    name: '11 margin 50%',
    lines: [A, B],
    density: '1.00',
    margin: '50',
    batch: { size: ['10', 'kg'], wastage: '0', pack: ['1', 'kg'], packCost: '0.00' },
    expected: { 'price.perKg': '32.4' },
  },
  {
    // 12. C_kg = 0.5×10 + 0.5×14 = 12; margin 25%: 12/0.75 = 16
    //     perPack (500 g, pack 0.30) = 0.5×12 + 0.3 = 6.3; price = 6.3/0.75 = 8.4
    name: '12 margin 25% on kg and pack',
    lines: [['MAT-A', '50', '10.00'], ['MAT-D', '50', '14.00']],
    density: '1.00',
    margin: '25',
    batch: { size: ['10', 'kg'], wastage: '0', pack: ['500', 'g'], packCost: '0.30' },
    expected: { 'cost.materialPerKg': '12', 'price.perKg': '16', 'cost.perPack': '6.3', 'price.perPack': '8.4' },
  },
  {
    // 13. USD price at fictional rate 48.00: 2.00 USD × 48 = 96 EGP/kg; C_kg = 0.5×10 + 0.5×96 = 5 + 48 = 53
    name: '13 foreign-currency price with explicit rate',
    lines: [['MAT-A', '50', '10.00'], ['MAT-U', '50', '2.00', { unit: 'USD' }]],
    fx: '48.00',
    expected: { 'cost.materialPerKg': '53' },
  },
  {
    // 14. w = 50%: perKg = 16.2/0.5 = 32.4; net 100 kg => gross 100/0.5 = 200; materials = 16.2×200 = 3240
    name: '14 wastage 50%',
    lines: [A, B],
    density: '1.00',
    batch: { size: ['100', 'kg'], wastage: '50', pack: ['1', 'kg'], packCost: '0.00' },
    expected: { 'cost.perKg': '32.4', 'batch.grossMass': '200', 'cost.materialBatch': '3240' },
  },
  {
    // 15. C_kg = 12; w = 40%: net 30 kg => gross 30/0.6 = 50; perKg = 12/0.6 = 20; materials = 12×50 = 600
    name: '15 wastage 40%',
    lines: [['MAT-A', '100', '12.00']],
    density: '1.00',
    batch: { size: ['30', 'kg'], wastage: '40', pack: ['1', 'kg'], packCost: '0.00' },
    expected: { 'batch.grossMass': '50', 'cost.perKg': '20', 'cost.materialBatch': '600' },
  },
  {
    // 16. ρ 0.9, net 20 L = 18 kg, w 10%: gross = 18/0.9 = 20 kg; perKg = 9/0.9 = 10; perL = 10×0.9 = 9
    //     pack 400 mL: N = 20000/400 = 50, rem 0; pack mass = 0.4×0.9 = 0.36 kg; perPack = 0.36×10 + 2.5 = 6.1
    //     materials = 9×20 = 180; packaging = 50×2.5 = 125; batch = 305
    name: '16 wastage with density and packs',
    lines: [['MAT-A', '100', '9.00']],
    density: '0.90',
    batch: { size: ['20', 'L'], wastage: '10', pack: ['400', 'mL'], packCost: '2.50' },
    expected: {
      'batch.netMass': '18', 'batch.grossMass': '20', 'cost.perKg': '10', 'cost.perL': '9', 'batch.fullPacks': '50',
      'cost.perPack': '6.1', 'cost.materialBatch': '180', 'cost.packagingBatch': '125', 'cost.batch': '305',
    },
  },
  {
    // 17. 12 L × 1.25 kg/L = 15 kg; 150 g pack: N = 15000/150 = 100, rem 0
    //     C_kg = 20; perL = 20×1.25 = 25; perPack = 0.15×20 + 0.5 = 3.5
    name: '17 gram pack from litre batch',
    lines: [['MAT-A', '100', '20.00']],
    density: '1.25',
    batch: { size: ['12', 'L'], wastage: '0', pack: ['150', 'g'], packCost: '0.50' },
    expected: { 'batch.netMass': '15', 'batch.fullPacks': '100', 'batch.remainder': '0', 'cost.perL': '25', 'cost.perPack': '3.5' },
  },
  {
    // 18. 7.5 kg batch, 1 kg pack: N = floor(7500/1000) = 7, rem = 7500 − 7000 = 500 g
    //     C_kg = 4; perPack = 1×4 + 0 = 4; batch = 4×7.5 + 7×0 = 30
    name: '18 kg pack remainder',
    lines: [['MAT-A', '100', '4.00']],
    density: '1.00',
    batch: { size: ['7.5', 'kg'], wastage: '0', pack: ['1', 'kg'], packCost: '0.00' },
    expected: { 'batch.fullPacks': '7', 'batch.remainder': '500', 'cost.perPack': '4', 'cost.batch': '30' },
  },
  {
    // 19. L7 price allowed only by a signed override: cost = 1.00 × 10 = 10, with an override warning
    name: '19 L7 with signed override',
    lines: [['MAT-A', '100', '10.00', { confidence: 'L7_estimate' }]],
    overrides: [{ materialId: 'MAT-A', property: 'price', signedBy: 'chemist-1', reason: 'quote pending' }],
    expected: { 'cost.materialPerKg': '10' },
    warnings: ['confidence.l7_override:MAT-A:price'],
  },
  {
    // 20. policy floor L3, price L5: value is used (1.00 × 10 = 10) but warned
    name: '20 confidence below policy warns',
    lines: [['MAT-A', '100', '10.00', { confidence: 'L5_reference' }]],
    floor: 'L3_standard',
    expected: { 'cost.materialPerKg': '10' },
    warnings: ['confidence.below_policy:MAT-A:price:L5_reference'],
  },
];

describe('F-02 hand-calculated cost cases', () => {
  it('has exactly 20 cases', () => {
    expect(cases).toHaveLength(20);
  });

  it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    const out = calculate(inputOf(c));
    for (const [key, value] of Object.entries(c.expected)) {
      expect(out.results[key]?.value, key).toBe(value);
    }
    for (const key of c.absent ?? []) {
      expect(out.results[key], `${key} must be absent`).toBeUndefined();
    }
    expect(out.missing.map((m) => m.property)).toEqual(c.missing ?? []);
    expect(out.ok).toBe((c.missing ?? []).length === 0);
    expect(out.warnings).toEqual(c.warnings ?? []);
  });
});
