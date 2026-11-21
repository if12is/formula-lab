import { describe, expect, it } from 'vitest';
import { optimize } from './optimize';
import { CASE1_MATERIALS, case2, egp, mat, profile } from './test-fixtures';

const lines = (o: Awaited<ReturnType<typeof optimize>>): [string, string][] => o.formula.map((l) => [l.materialId, l.percentWW]);

describe('optimize: hand-computed optima', () => {
  it('case 1: one active material + water. A 20 %, water 80 %, cost 20.8', async () => {
    // min x_A·1.00 + x_W·0.01 (EGP per kg per percent), x_A + x_W = 100, 0.5·x_A >= 10
    // -> x_A = 20, x_W = 80; cost = 20·100/100 + 80·1/100 = 20.8
    // duals: y_sum = 0.01 (water is basic), y_active = (1 − 0.01)/0.5 = 1.98
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: CASE1_MATERIALS, qsMaterialId: 'WATER' });
    expect(out.status).toBe('optimal');
    expect(lines(out)).toEqual([['WATER', '80.000'], ['AAA', '20.000']]);
    expect(out.costPerKg).toBe('20.8');
    expect(out.binding.map((b) => [b.id, b.slack, b.dual])).toEqual([['active.min', '0', '1.98']]);
    expect(out.duals?.rows).toEqual([{ id: 'sum', dual: '0.01' }, { id: 'active.min', dual: '1.98' }]);
    expect(out.duals?.reducedCosts.every((r) => r.reducedCost === '0')).toBe(true);
    expect(out.formula[1]).toMatchObject({ materialId: 'AAA', priceEGPperKg: '100', priceSourceId: 'TEST-FIXTURE', priceConfidence: 'L2_supplier_tds', costEGPperKg: '20' });
    expect(out.solver).toMatchObject({ name: 'highs-wasm', packageVersion: '1.8.0', roundingAttempt: '1' });
    expect(out.inputHash).toMatch(/^[0-9a-f]{64}$/);
    expect(out.outputHash).toMatch(/^[0-9a-f]{64}$/);
  });

  it('case 2: function bound + active min across three materials. S1 22.5, S2 5, water 72.5, cost 26.225', async () => {
    // S2 (150 EGP/kg, 60 %) costs 250 per active-kg vs S1 (80, 40 %) 200, so S2 sits at its function bound x_S2 = 5 (active 3);
    // the remaining 9 active points need x_S1 = 9/0.4 = 22.5; water = 100 − 22.5 − 5 = 72.5
    // cost = 22.5·0.80 + 5·1.50 + 72.5·0.01 = 18 + 7.5 + 0.725 = 26.225
    // duals (all three variables basic): y_sum = 0.01; y_active = (0.8 − 0.01)/0.4 = 1.975; y_fn = 1.5 − 0.01 − 0.6·1.975 = 0.305
    const out = await optimize(case2());
    expect(out.status).toBe('optimal');
    expect(lines(out)).toEqual([['WATER', '72.500'], ['S1', '22.500'], ['S2', '5.000']]);
    expect(out.costPerKg).toBe('26.225');
    expect(out.binding.map((b) => [b.id, b.dual])).toEqual([['active.min', '1.975'], ['fn.amphoteric.min', '0.305']]);
    expect(out.binding[0]?.message?.key).toBe('optimizer.infeasible.active_min_too_high');
  });

  it('rounding: a vertex off the 0.001 grid triggers the safety-margin attempt and the result is exactly verified', async () => {
    // B (55 EGP/kg, 30 %) and water; active >= 10. The LP optimum is B = 33.3333…, which rounds to 33.333 and gives
    // active 9.9999 < 10, so attempt 1 fails exact verification. Attempt 2 tightens active.min by
    // 0.0005·(|0.5 − 0| + |0.3 − 0|) = 0.0004 -> B = 10.0004/0.3 = 33.3346… -> 33.335 (active 10.0005 >= 10), water 66.665.
    // cost = 33.335·55/100 + 66.665/100 = 18.33425 + 0.66665 = 19.0009 (LP optimum 19.0)
    const mats = [mat('A', '100', '50'), mat('B', '55', '30'), mat('WATER', '1', '0')];
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: mats, qsMaterialId: 'WATER' });
    expect(out.status).toBe('optimal');
    expect(out.solver.roundingAttempt).toBe('2');
    expect(out.warnings).toContain('optimizer.warning.rounding_margin_applied');
    expect(lines(out)).toEqual([['WATER', '66.665'], ['B', '33.335']]);
    expect(out.costPerKg).toBe('19.0009');
  });

  it('required material gets its explicit min; forbidden materials are excluded even if cheapest', async () => {
    const mats = [mat('S1', '80', '40'), mat('CHEAP', '1', '90'), mat('WATER', '1', '0'), mat('DYE', '10', '0')];
    const out = await optimize({
      profile: profile({ totalActiveMinPct: '12', forbiddenMaterials: ['CHEAP'], requiredMaterials: ['DYE'], materialBounds: [{ materialId: 'DYE', min: '0.5', max: '0.5' }] }),
      materials: mats,
      qsMaterialId: 'WATER',
    });
    // S1 = 12/0.4 = 30, DYE = 0.5, water = 69.5; cost = 30·0.8 + 0.5·0.1 + 69.5·0.01 = 24 + 0.05 + 0.695 = 24.745
    expect(lines(out)).toEqual([['WATER', '69.500'], ['S1', '30.000'], ['DYE', '0.500']]);
    expect(out.costPerKg).toBe('24.745');
  });
});

describe('optimize: missing data stops the solve (never zero)', () => {
  it('lists every material without a price / active value; L7 needs a signed override', async () => {
    const out = await optimize({
      profile: profile({ totalActiveMinPct: '10' }),
      materials: [{ materialId: 'A', activeMatterPct: CASE1_MATERIALS[1]?.activeMatterPct }, { ...mat('B', '5', '30'), price: { ...egp('5'), confidence: 'L7_estimate' } }, mat('WATER', '1', '0')],
    });
    expect(out.status).toBe('missing');
    expect(out.missing).toEqual([
      { materialId: 'A', property: 'price', reason: 'input.missing' },
      { materialId: 'B', property: 'price', reason: 'confidence.l7_requires_signed_override' },
    ]);
    expect(out.solver.lpSolves).toBe('0');
  });

  it('function tags are required when the profile has function bounds', async () => {
    const out = await optimize({ ...case2(), materials: [{ materialId: 'S1', price: egp('80'), activeMatterPct: CASE1_MATERIALS[1]?.activeMatterPct }, ...case2().materials.slice(1)] });
    expect(out.missing).toEqual([{ materialId: 'S1', property: 'functions', reason: 'input.missing' }]);
  });

  it('required materials need an explicit min > 0 and must be offered; q.s. must be usable', async () => {
    const out = await optimize({
      profile: profile({ totalActiveMinPct: '10', requiredMaterials: ['DYE', 'ABSENT'] }),
      materials: [mat('DYE', '10', '0'), mat('WATER', '1', '0'), mat('A', '100', '50')],
      qsMaterialId: 'NOPE',
    });
    expect(out.missing).toEqual([
      { materialId: 'ABSENT', property: 'material', reason: 'optimizer.required_material_not_offered' },
      { materialId: 'ABSENT', property: 'profile.materialBounds.ABSENT.min', reason: 'optimizer.required_material_needs_min' },
      { materialId: 'DYE', property: 'profile.materialBounds.DYE.min', reason: 'optimizer.required_material_needs_min' },
      { materialId: 'NOPE', property: 'qsMaterial', reason: 'optimizer.qs_material_not_usable' },
    ]);
  });

  it('a target retail price without reverse-pricing inputs reports each missing input', async () => {
    const out = await optimize({ ...case2({ profile: profile({ totalActiveMinPct: '12', retailPrice: '22.8', per: 'pack' }) }) });
    expect(out.status).toBe('missing');
    expect(out.missing.map((m) => m.property)).toEqual([
      'reversePricing.vatRatePct', 'reversePricing.retailerMarginPct', 'reversePricing.wholesalerMarginPct',
      'reversePricing.distributionCostPerUnit', 'reversePricing.factoryMarginPct', 'reversePricing.fillKg', 'reversePricing.marginBasis',
    ]);
  });

  it('duplicate material ids are an input error', async () => {
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: [mat('A', '1', '1'), mat('A', '2', '2')] });
    expect(out.status).toBe('invalid');
    expect(out.errors).toEqual([{ key: 'optimizer.input.duplicate_material', params: { materialId: 'A' } }]);
  });

  it('measured requirements are passed through with a not-enforced warning', async () => {
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10', measuredRequirements: ['pH', 'viscosity_mPas'] }), materials: CASE1_MATERIALS, qsMaterialId: 'WATER' });
    expect(out.measuredRequirements).toEqual(['pH', 'viscosity_mPas']);
    expect(out.warnings).toContain('optimizer.warning.measured_requirements_not_enforced:pH,viscosity_mPas');
  });
});
