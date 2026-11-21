import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { calculate } from './calculate';
import { ENGINE_VERSION, type EngineInput, type SourcedValue } from './contract';
import { qty } from './core/quantity';
import { leavesOf } from './trace/trace';

const sv = (value: string, over: Partial<SourcedValue> = {}): SourcedValue => ({
  value, unit: 'EGP', sourceId: 'SRC', confidence: 'L2_supplier_tds', validFrom: '2026-01-01', ...over,
});

function base(): EngineInput {
  return {
    engineVersion: ENGINE_VERSION,
    datasetVersion: 'ds-1',
    formula: {
      lines: [{ materialId: 'MAT-A', percentWW: '60' }, { materialId: 'MAT-B', percentWW: '40' }],
      productDensity: sv('1.02', { unit: 'kg/L', sourceId: 'LAB-RHO', confidence: 'L1_coa_batch' }),
    },
    batch: { size: { value: '50', unit: 'L' }, wastagePct: '0', packSize: { value: '500', unit: 'mL' } },
    prices: { 'MAT-A': sv('10.00', { sourceId: 'SRC-A' }), 'MAT-B': sv('25.50', { sourceId: 'SRC-B' }) },
    policy: { rounding: 'default' },
    packaging: { perPack: sv('3.00', { sourceId: 'SRC-PACK' }) },
  };
}

describe('calculate', () => {
  it('returns keyed results with traces, hashes and versions', () => {
    const out = calculate(base());
    expect(out.ok).toBe(true);
    expect(out.missing).toEqual([]);
    expect(out.engineVersion).toBe(ENGINE_VERSION);
    expect(out.datasetVersion).toBe('ds-1');
    expect(out.inputHash).toMatch(/^[0-9a-f]{64}$/);
    expect(out.outputHash).toMatch(/^[0-9a-f]{64}$/);
    for (const key of ['cost.perKg', 'cost.perL', 'cost.perPack', 'batch.grossMass', 'batch.fullPacks']) {
      expect(out.results[key]?.trace.label, key).toBeTruthy();
    }
    expect(out.results['batch.fullPacks']?.value).toBe('100');
  });

  it('F-10: cost/kg trace contains every price leaf with sourceId and confidence', () => {
    const out = calculate(base());
    const leaves = leavesOf(out.results['cost.perKg']?.trace ?? (undefined as never));
    const prices = leaves.filter((n) => n.label.endsWith('price (EGP/kg)'));
    expect(prices.map((n) => [n.label, n.sourceId, n.confidence])).toEqual([
      ['MAT-A price (EGP/kg)', 'SRC-A', 'L2_supplier_tds'],
      ['MAT-B price (EGP/kg)', 'SRC-B', 'L2_supplier_tds'],
    ]);
  });

  it('determinism: key order does not change inputHash or outputHash', () => {
    const a = base();
    const b: EngineInput = {
      policy: { rounding: 'default' },
      prices: { 'MAT-B': sv('25.50', { sourceId: 'SRC-B' }), 'MAT-A': { validFrom: '2026-01-01', confidence: 'L2_supplier_tds', sourceId: 'SRC-A', unit: 'EGP', value: '10.00' } },
      packaging: a.packaging,
      batch: { packSize: { unit: 'mL', value: '500' }, wastagePct: '0', size: { unit: 'L', value: '50' } },
      formula: { productDensity: a.formula.productDensity, lines: a.formula.lines.map((l) => ({ percentWW: l.percentWW, materialId: l.materialId })) },
      datasetVersion: 'ds-1',
      engineVersion: ENGINE_VERSION,
    };
    const x = calculate(a);
    const y = calculate(b);
    expect(y.inputHash).toBe(x.inputHash);
    expect(y.outputHash).toBe(x.outputHash);
    expect(calculate(a)).toEqual(x);
  });

  it('different input => different inputHash; Quantity instances hash like JSON quantities', () => {
    const a = base();
    const withInstance: EngineInput = { ...a, batch: { ...(a.batch as NonNullable<EngineInput['batch']>), size: qty('50', 'L'), packSize: qty('500', 'mL') } };
    expect(calculate(withInstance).inputHash).toBe(calculate(a).inputHash);
    const changed = base();
    changed.prices['MAT-A'] = sv('10.01');
    expect(calculate(changed).inputHash).not.toBe(calculate(a).inputHash);
  });

  it('missing prices: lists every material, ok=false, other results still computed', () => {
    const input = base();
    input.prices = {};
    const out = calculate(input);
    expect(out.ok).toBe(false);
    expect(out.missing.map((m) => [m.materialId, m.property])).toEqual([['MAT-A', 'price'], ['MAT-B', 'price']]);
    expect(out.results['cost.materialPerKg']).toBeUndefined();
    expect(out.results['batch.fullPacks']?.value).toBe('100');
  });

  it('invalid formula stops everything and maps errors to missing with message keys', () => {
    const input = base();
    input.formula.lines = [{ materialId: 'MAT-A', percentWW: '60' }, { materialId: 'MAT-B', percentWW: '30' }];
    const out = calculate(input);
    expect(out.ok).toBe(false);
    expect(out.missing).toEqual([{ property: 'formula', reason: 'formula.sum_not_100' }]);
    expect(out.results).toEqual({});
    input.formula.lines = [{ materialId: 'MAT-A', percentWW: '0' }, { materialId: 'MAT-A', percentWW: '100' }];
    expect(calculate(input).missing.map((m) => [m.materialId, m.reason])).toEqual([
      ['MAT-A', 'formula.percent_not_positive'],
      ['MAT-A', 'formula.duplicate_material'],
    ]);
  });

  it('no density => kg-only plus productDensity missing; no duplicates', () => {
    const input = base();
    delete input.formula.productDensity;
    const out = calculate(input);
    expect(out.ok).toBe(false);
    expect(out.missing.map((m) => m.property)).toEqual(['productDensity']);
    expect(out.results['cost.perKg']?.value).toBe('16.2');
    expect(out.results['cost.perL']).toBeUndefined();
  });

  it('no batch: only material cost per kg', () => {
    const input = base();
    delete input.batch;
    const out = calculate(input);
    expect(out.ok).toBe(true);
    expect(Object.keys(out.results)).toEqual(['cost.materialPerKg']);
  });

  it('batch without packaging.perPack => missing packaging.perPack, other results still computed', () => {
    const input = base();
    delete input.packaging;
    const out = calculate(input);
    expect(out.ok).toBe(false);
    expect(out.missing).toEqual([{ property: 'packaging.perPack', reason: 'cost.price_missing' }]);
    expect(out.results['cost.perPack']).toBeUndefined();
    expect(out.results['cost.perKg']?.value).toBe('16.2');
    expect(out.warnings).toEqual([]);
  });

  it('pricing margin >= 100 is an error entry, not a price', () => {
    const input = base();
    input.pricing = { marginPct: '100' };
    const out = calculate(input);
    expect(out.ok).toBe(false);
    expect(out.missing[0]).toMatchObject({ property: 'marginPct', reason: 'cost.margin_must_be_below_100' });
    expect(out.results['price.perKg']).toBeUndefined();
  });

  it('warns when the pinned engine version differs', () => {
    const input = base();
    input.engineVersion = '0.0.1';
    expect(calculate(input).warnings).toEqual(['engine.version_mismatch']);
  });

  it('invalid wastage is reported once', () => {
    const input = base();
    (input.batch as NonNullable<EngineInput['batch']>).wastagePct = '100';
    const out = calculate(input);
    expect(out.missing.filter((m) => m.property === 'wastagePct')).toHaveLength(1);
  });
});

describe('F-03 lint: no ceil in engine source', () => {
  function walk(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
      const p = join(dir, name);
      return statSync(p).isDirectory() ? walk(p) : [p];
    });
  }
  it('no ceil/ROUND_CEIL anywhere in packages/engine/src (comments excluded)', () => {
    const offenders = walk(join(__dirname))
      .filter((f) => f.endsWith('.ts') && !f.endsWith('.test.ts'))
      .filter((f) => {
        const code = readFileSync(f, 'utf8')
          .split('\n')
          .filter((line) => !/^\s*(\/\/|\/?\*)/.test(line))
          .join('\n');
        return /ceil/i.test(code);
      });
    expect(offenders).toEqual([]);
  });
});
