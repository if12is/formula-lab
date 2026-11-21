import { describe, expect, it } from 'vitest';
import { calculate } from '../calculate';
import { ENGINE_VERSION, type EngineInput, type EngineOutput, type SourcedValue } from '../contract';
import { Quantity } from '../core/quantity';
import { outputHash } from '../hash';
import { leavesOf } from '../trace/trace';
import { buildBatchReport, computeReportHash, verifyBatchReport, type BatchReport, type BatchReportEnvelope } from './batch-report';

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
    pricing: { marginPct: '30' },
  };
}

const meta = { formulaCode: 'F-001', formulaVersion: '3', formulaVersionHash: 'abc123', batchRef: 'B-2026-001' };

function build(input: EngineInput, m = meta, allowDraft = false): BatchReport {
  const r = buildBatchReport(input, calculate(input), m, { allowDraft });
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
}

describe('buildBatchReport', () => {
  it('builds a verified document with header, blocks and display strings', () => {
    const out = calculate(base());
    const rep = build(base());
    expect(rep.draft).toBe(false);
    expect(rep.header).toMatchObject({ ...meta, engineVersion: ENGINE_VERSION, datasetVersion: 'ds-1', inputHash: out.inputHash, outputHash: out.outputHash });
    expect(rep.reportHash).toMatch(/^[0-9a-f]{64}$/);
    expect(verifyBatchReport(rep)).toBe(true);
    // 50 L x 1.02 = 51 kg = 51000 g; 60% => 30600 g
    expect(rep.formula.totalMassG).toBe('51000');
    expect(rep.formula.lines[0]).toMatchObject({ materialId: 'MAT-A', percentWW: '60', percentDisplay: '60.000', massG: '30600', massDisplay: '30600.0' });
    expect(rep.formula.percentSumDisplay).toBe('100.000');
    expect(rep.cost.map((c) => c.key)).toEqual(expect.arrayContaining(['cost.perKg', 'cost.perPack', 'cost.batch', 'price.perKg']));
    expect(rep.cost.find((c) => c.key === 'cost.perKg')).toMatchObject({ unit: 'EGP', displayUnit: 'EGP' });
    expect(rep.batch.find((b) => b.key === 'batch.fullPacks')).toMatchObject({ value: '100', display: '100', displayUnit: 'pc' });
    expect(rep.batch.find((b) => b.key === 'batch.grossMass')).toMatchObject({ unit: 'kg', display: '51000.0', displayUnit: 'g' });
    expect(rep.batch.find((b) => b.key === 'batch.netVolume')).toMatchObject({ display: '50000', displayUnit: 'mL' });
    expect(JSON.stringify(rep)).not.toMatch(/issuedAt/);
  });

  it('same hash for same inputs, across key orders and Quantity instances', () => {
    const a = base();
    const b: EngineInput = {
      pricing: { marginPct: '30' },
      packaging: { perPack: { validFrom: '2026-01-01', confidence: 'L2_supplier_tds', sourceId: 'SRC-PACK', unit: 'EGP', value: '3.00' } },
      policy: { rounding: 'default' },
      prices: { 'MAT-B': a.prices['MAT-B'] as SourcedValue, 'MAT-A': a.prices['MAT-A'] as SourcedValue },
      batch: { packSize: new Quantity('500', 'mL'), wastagePct: '0', size: new Quantity('50', 'L') },
      formula: { productDensity: a.formula.productDensity, lines: a.formula.lines.map((l) => ({ percentWW: l.percentWW, materialId: l.materialId })) },
      datasetVersion: 'ds-1',
      engineVersion: ENGINE_VERSION,
    };
    const ra = build(a);
    const rb = build(b, { batchRef: meta.batchRef, formulaVersionHash: meta.formulaVersionHash, formulaVersion: meta.formulaVersion, formulaCode: meta.formulaCode });
    expect(rb.reportHash).toBe(ra.reportHash);
    expect(build(a).reportHash).toBe(ra.reportHash);
  });

  it('the issue stamp is outside the hash: envelope does not change reportHash', () => {
    const report = build(base());
    const e1: BatchReportEnvelope = { report, issuedAt: '2026-11-21T10:00:00Z', issuedBy: 'u1' };
    const e2: BatchReportEnvelope = { report, issuedAt: '2026-12-01T09:00:00Z', issuedBy: 'u2' };
    expect(e1.report.reportHash).toBe(e2.report.reportHash);
    expect(verifyBatchReport(e2.report)).toBe(true);
  });

  const mutations: [string, (i: EngineInput) => void, string?][] = [
    ['a line percent', (i) => { i.formula.lines = [{ materialId: 'MAT-A', percentWW: '61' }, { materialId: 'MAT-B', percentWW: '39' }]; }],
    ['a price', (i) => { i.prices['MAT-A'] = sv('10.01', { sourceId: 'SRC-A' }); }],
    ['a price source', (i) => { i.prices['MAT-A'] = sv('10.00', { sourceId: 'SRC-A2' }); }],
    ['the dataset version', (i) => { i.datasetVersion = 'ds-2'; }],
    ['the margin', (i) => { i.pricing = { marginPct: '31' }; }],
  ];
  it.each(mutations)('changing %s changes the hash', (_n, mutate) => {
    const i = base();
    mutate(i);
    expect(build(i).reportHash).not.toBe(build(base()).reportHash);
  });

  it('changing formula version, version hash, batch ref or code changes the hash', () => {
    const h = build(base()).reportHash;
    for (const m of [{ ...meta, formulaVersion: '4' }, { ...meta, formulaVersionHash: 'zzz' }, { ...meta, batchRef: 'B-2' }, { ...meta, formulaCode: 'F-002' }]) {
      expect(build(base(), m).reportHash).not.toBe(h);
    }
  });

  it('changing the engine version changes the hash (via the engine output)', () => {
    const input = base();
    const out = calculate(input);
    const forged: EngineOutput = { ...out, engineVersion: '9.9.9' };
    forged.outputHash = outputHash(forged);
    const r = buildBatchReport(input, forged, meta);
    expect(r.ok && r.value.reportHash).not.toBe(build(input).reportHash);
    expect(r.ok && r.value.header.engineVersion).toBe('9.9.9');
  });

  it('verifyBatchReport detects tampering', () => {
    const rep = build(base());
    expect(verifyBatchReport(rep)).toBe(true);
    const tampered: BatchReport = { ...rep, formula: { ...rep.formula, totalMassDisplay: '1.0' } };
    expect(verifyBatchReport(tampered)).toBe(false);
    expect(computeReportHash(rep)).toBe(rep.reportHash);
  });

  describe('draft behaviour', () => {
    const incomplete = (): EngineInput => {
      const i = base();
      delete i.prices['MAT-B'];
      return i;
    };

    it('refuses an output with gaps by default, listing the gaps', () => {
      const input = incomplete();
      const r = buildBatchReport(input, calculate(input), meta);
      expect(r.ok).toBe(false);
      if (!r.ok) {
        expect(r.missing[0]).toEqual({ property: 'output', reason: 'report.output_not_ok' });
        expect(r.missing.some((m) => m.materialId === 'MAT-B')).toBe(true);
      }
    });

    it('allowDraft builds a flagged document whose flag is hashed', () => {
      const input = incomplete();
      const rep = build(input, meta, true);
      expect(rep.draft).toBe(true);
      expect(rep.missing.length).toBeGreaterThan(0);
      expect(verifyBatchReport(rep)).toBe(true);
      const flipped: BatchReport = { ...rep, draft: false };
      expect(verifyBatchReport(flipped)).toBe(false);
    });

    it('allowDraft on a complete output stays non-draft and hashes like the normal build', () => {
      expect(build(base(), meta, true)).toEqual(build(base()));
    });

    it('draft with no gross mass leaves mass columns null', () => {
      const input = base();
      delete input.formula.productDensity;
      const rep = build(input, meta, true);
      expect(rep.formula.totalMassG).toBeNull();
      expect(rep.formula.massRounding).toBeNull();
      expect(rep.formula.lines.every((l) => l.massG === null && l.massDisplay === null)).toBe(true);
    });
  });

  describe('preconditions', () => {
    it('rejects blank meta fields', () => {
      const r = buildBatchReport(base(), calculate(base()), { ...meta, batchRef: '  ' });
      expect(r.ok).toBe(false);
      if (!r.ok) {
        expect(r.missing).toEqual([{ property: 'meta.batchRef', reason: 'report.meta_required' }]);
      }
    });

    it('rejects an output that does not belong to the input', () => {
      const other = base();
      other.datasetVersion = 'ds-other';
      const r = buildBatchReport(base(), calculate(other), meta);
      expect(!r.ok && r.missing.map((m) => m.reason)).toEqual(['report.input_output_mismatch']);
    });

    it('rejects an output whose own hash does not verify', () => {
      const out = { ...calculate(base()), warnings: ['x'] };
      const r = buildBatchReport(base(), out, meta);
      expect(!r.ok && r.missing.map((m) => m.reason)).toEqual(['report.output_hash_mismatch']);
    });
  });

  describe('sources table', () => {
    it('lists every distinct sourceId found in trace leaves, with confidences', () => {
      const input = base();
      const out = calculate(input);
      const rep = build(input);
      const expected = new Set<string>();
      for (const r of Object.values(out.results)) {
        for (const leaf of leavesOf(r.trace)) {
          if (leaf.sourceId !== undefined) {
            expected.add(leaf.sourceId);
          }
        }
      }
      expect(rep.sources.map((s) => s.sourceId)).toEqual([...expected].sort());
      expect(rep.sources.map((s) => s.sourceId)).toEqual(expect.arrayContaining(['SRC-A', 'SRC-B', 'SRC-PACK', 'LAB-RHO']));
      expect(rep.sources.find((s) => s.sourceId === 'LAB-RHO')?.confidences).toEqual(['L1_coa_batch']);
      expect(rep.sources.find((s) => s.sourceId === 'SRC-A')).toMatchObject({ confidences: ['L2_supplier_tds'], labels: ['MAT-A price (EGP/kg)'] });
    });

    it('merges a source seen with several confidences and skips unsourced leaves', () => {
      const input = base();
      input.prices['MAT-B'] = sv('25.50', { sourceId: 'SRC-A', confidence: 'L3_standard' });
      const rep = build(input);
      expect(rep.sources.find((s) => s.sourceId === 'SRC-A')?.confidences).toEqual(['L2_supplier_tds', 'L3_standard']);
      expect(rep.sources.filter((s) => s.sourceId === 'SRC-A')).toHaveLength(1);
    });
  });

  describe('rounding residual', () => {
    it('reports when displayed masses do not sum to the displayed total', () => {
      const input = base();
      input.formula.lines = [
        { materialId: 'MAT-A', percentWW: '33.333' },
        { materialId: 'MAT-B', percentWW: '33.333' },
        { materialId: 'MAT-C', percentWW: '33.334' },
      ];
      input.prices['MAT-C'] = sv('5.00', { sourceId: 'SRC-C' });
      input.batch = { size: { value: '1', unit: 'kg' }, wastagePct: '0', packSize: { value: '500', unit: 'g' } };
      const rep = build(input);
      const mr = rep.formula.massRounding;
      // 1000 g: 333.33 / 333.33 / 333.34 -> 333.3 + 333.3 + 333.3 = 999.9 vs 1000.0
      expect(rep.formula.lines.map((l) => l.massDisplay)).toEqual(['333.3', '333.3', '333.3']);
      expect(mr).toMatchObject({ sumOfRoundedParts: '999.9', roundedTotal: '1000.0', residual: '0.1', hasResidual: true });
    });

    it('reports no residual when the display adds up', () => {
      expect(build(base()).formula.massRounding?.hasResidual).toBe(false);
    });
  });

  it('works for a cost-only input without a batch (no mass columns, no batch block)', () => {
    const input = base();
    delete input.batch;
    delete input.packaging;
    delete input.pricing;
    const rep = build(input);
    expect(rep.batch).toEqual([]);
    expect(rep.formula.totalMassG).toBeNull();
    expect(rep.cost.map((c) => c.key)).toEqual(['cost.materialPerKg']);
  });

  it('non-money/non-mass/non-volume units fall back to percent-step display', () => {
    const input = base();
    const out = calculate(input);
    const key = 'batch.custom';
    const body: EngineOutput = { ...out, results: { ...out.results, [key]: { value: '1.23456', unit: '%w/w', trace: { op: 'input', label: 'x', value: '1.23456', unit: '%w/w', inputs: [] } } } };
    body.outputHash = outputHash(body);
    const r = buildBatchReport(input, body, meta);
    expect(r.ok && r.value.batch.find((b) => b.key === key)).toMatchObject({ display: '1.235', displayUnit: '%w/w' });
  });
});
