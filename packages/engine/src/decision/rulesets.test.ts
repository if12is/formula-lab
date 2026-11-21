import { describe, expect, it } from 'vitest';
import approveJson from '../../data/decisions/approve-formula-version.json';
import releaseJson from '../../data/decisions/release-batch.json';
import publishJson from '../../data/decisions/publish-to-sanad.json';
import { coverageGap, countString, evaluateSpecs, type FormulaApprovalContext, type PublishToSanadContext, type ReleaseBatchContext } from './context';
import { hashDecisionRuleSet, validateDecisionRuleSet, type DecisionRuleSet } from './dsl';
import { evaluateDecision, type DecisionContext, type FailedRule } from './evaluate';

function load(json: unknown): DecisionRuleSet {
  const v = validateDecisionRuleSet(json);
  if (!v.ok) {
    throw new Error(JSON.stringify(v.missing));
  }
  return v.value;
}

function failures(rs: DecisionRuleSet, ctx: DecisionContext): FailedRule[] {
  const r = evaluateDecision(rs, ctx);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value.failed;
}

const ids = (f: FailedRule[]) => f.map((x) => x.ruleId);

describe('approve-formula-version (Y-02)', () => {
  const rs = load(approveJson);
  const good = (): FormulaApprovalContext => ({
    formula: { validateOk: true, totalPct: '100.0000' },
    engine: { missingCount: '0', missingProperties: [] },
    compliance: { overall: 'PASS' },
    criticalMaterials: { ids: ['mat-a', 'mat-b'], confidences: ['L1_coa_batch', 'L2_supplier_tds'] },
    lab: { requiredProperties: ['ph', 'viscosity'], missingProperties: [], missingCount: '0' },
    deviations: { openCount: '0', openIds: [] },
  });

  it('every rule cites a basis and the set validates', () => {
    expect(rs.rules.every((r) => r.basis?.startsWith('plan/01-phases/Y-decision-engine/tasks.md'))).toBe(true);
  });

  it('allows a fully compliant formula', () => {
    const r = evaluateDecision(rs, good());
    expect(r.ok && r.value.decision).toBe('allowed');
    expect(r.ok && r.value.passed).toHaveLength(7);
  });

  it('blocks when the engine validate result is not ok', () => {
    const c = good();
    c.formula.validateOk = false;
    expect(ids(failures(rs, c))).toEqual(['AFV-01']);
  });

  it('blocks when the sum is not 100', () => {
    const c = good();
    c.formula.totalPct = '99.5';
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['AFV-02']);
    expect(f[0]?.params).toEqual({ totalPct: '99.5' });
    expect(f[0]?.remedyKey).toBe('decision.remedy.adjust_formula_to_100');
  });

  it('blocks when the engine output has missing data and names it', () => {
    const c = good();
    c.engine = { missingCount: '2', missingProperties: ['mat-a:density', 'mat-b:price'] };
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['AFV-03']);
    expect(f[0]?.params.missingProperties).toBe('mat-a:density,mat-b:price');
  });

  it('blocks on compliance FAIL and on UNKNOWN', () => {
    for (const overall of ['FAIL', 'UNKNOWN'] as const) {
      const c = good();
      c.compliance.overall = overall;
      const f = failures(rs, c);
      expect(ids(f)).toEqual(['AFV-04']);
      expect(f[0]?.params).toEqual({ overall });
    }
  });

  it('blocks when a critical material is below L2 and lists the critical ids', () => {
    const c = good();
    c.criticalMaterials.confidences = ['L1_coa_batch', 'L3_standard'];
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['AFV-05']);
    expect(f[0]?.params).toEqual({ materialIds: 'mat-a,mat-b' });
  });

  it('allows a formula with no critical materials', () => {
    const c = good();
    c.criticalMaterials = { ids: [], confidences: [] };
    expect(failures(rs, c)).toEqual([]);
  });

  it('blocks when required lab results are missing', () => {
    const c = good();
    const missingProperties = coverageGap(c.lab.requiredProperties, ['ph']);
    c.lab = { ...c.lab, missingProperties, missingCount: countString(missingProperties) };
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['AFV-06']);
    expect(f[0]?.params).toEqual({ missingProperties: 'viscosity' });
  });

  it('blocks when deviations are open', () => {
    const c = good();
    c.deviations = { openCount: '1', openIds: ['dev-7'] };
    expect(failures(rs, c)[0]).toMatchObject({ ruleId: 'AFV-07', params: { deviationIds: 'dev-7' } });
  });

  it('collects every failure at once', () => {
    const c = good();
    c.formula = { validateOk: false, totalPct: '98' };
    c.compliance.overall = 'FAIL';
    c.deviations = { openCount: '3', openIds: ['a', 'b', 'c'] };
    expect(ids(failures(rs, c))).toEqual(['AFV-01', 'AFV-02', 'AFV-04', 'AFV-07']);
  });

  it('blocks (reason missing) for every absent section', () => {
    const base = good() as unknown as Record<string, unknown>;
    for (const section of Object.keys(base)) {
      const c = { ...base };
      delete c[section];
      const f = failures(rs, c as DecisionContext);
      expect(f.length).toBeGreaterThan(0);
      expect(f.every((x) => x.reason === 'missing')).toBe(true);
      const r = evaluateDecision(rs, c as DecisionContext);
      expect(r.ok && r.value.decision).toBe('blocked');
    }
  });
});

describe('release-batch (Y-03)', () => {
  const rs = load(releaseJson);
  const good = (): ReleaseBatchContext => ({
    formulaVersion: { id: 'fv-1', status: 'approved' },
    lots: { coaMissingCount: '0', coaMissingLotIds: [] },
    deviations: { unapprovedCount: '0', unapprovedIds: [] },
    labResults: { outOfSpecCount: '0', outOfSpecProperties: [], notEvaluatedCount: '0', notEvaluatedProperties: [] },
  });

  it('allows a releasable batch', () => {
    const r = evaluateDecision(rs, good());
    expect(r.ok && r.value.decision).toBe('allowed');
  });

  it('blocks when the formula version is not approved', () => {
    const c = good();
    c.formulaVersion.status = 'draft';
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['RB-01']);
    expect(f[0]?.params).toEqual({ formulaVersionId: 'fv-1', status: 'draft' });
  });

  it('blocks when the COA of a lot is missing', () => {
    const c = good();
    c.lots = { coaMissingCount: '1', coaMissingLotIds: ['LOT-9'] };
    expect(failures(rs, c)[0]).toMatchObject({ ruleId: 'RB-02', params: { lotIds: 'LOT-9' } });
  });

  it('blocks when deviations are not approved', () => {
    const c = good();
    c.deviations = { unapprovedCount: '2', unapprovedIds: ['d1', 'd2'] };
    expect(failures(rs, c)[0]).toMatchObject({ ruleId: 'RB-03', params: { deviationIds: 'd1,d2' } });
  });

  it('blocks on out-of-spec and on unevaluated results (limits from context)', () => {
    const spec = evaluateSpecs(
      [
        { property: 'ph', min: '5.0', max: '6.5' },
        { property: 'density', min: '0.98', max: '1.02' },
        { property: 'appearance', min: null, max: null },
      ],
      { ph: '7.1', density: '1.00' },
    );
    const c = good();
    c.labResults = {
      outOfSpecCount: countString(spec.outOfSpec),
      outOfSpecProperties: spec.outOfSpec,
      notEvaluatedCount: countString(spec.notEvaluated),
      notEvaluatedProperties: spec.notEvaluated,
    };
    const f = failures(rs, c);
    expect(f.map((x) => [x.ruleId, x.params.properties])).toEqual([
      ['RB-04', 'ph'],
      ['RB-05', 'appearance'],
    ]);
  });

  it('blocks (missing) when sections are absent; an absent formula id is unmet', () => {
    const base = good() as unknown as Record<string, unknown>;
    for (const section of ['lots', 'deviations', 'labResults']) {
      const c = { ...base };
      delete c[section];
      expect(failures(rs, c as DecisionContext).every((x) => x.reason === 'missing')).toBe(true);
    }
    const c = { ...base };
    delete c.formulaVersion;
    expect(ids(failures(rs, c as DecisionContext))).toEqual(['RB-01']);
  });
});

describe('publish-to-sanad (Y-05)', () => {
  const rs = load(publishJson);
  const good = (): PublishToSanadContext => ({
    cost: { approved: true, approvedUnitCost: '12.3456' },
    priceWaterfall: { missingCount: '0', missingProperties: [] },
    repricing: { maxMaterialPriceChangePct: '3.5', changedMaterialIds: ['m1'], thresholdPct: '5' },
  });

  it('allows a publishable product', () => {
    const r = evaluateDecision(rs, good());
    expect(r.ok && r.value.decision).toBe('allowed');
  });

  it('allows a change exactly at the threshold', () => {
    const c = good();
    c.repricing.maxMaterialPriceChangePct = '5.0';
    expect(failures(rs, c)).toEqual([]);
  });

  it('blocks when the cost is not approved or has no approved unit cost', () => {
    const a = good();
    a.cost.approved = false;
    expect(ids(failures(rs, a))).toEqual(['PS-01']);
    const b = good();
    delete b.cost.approvedUnitCost;
    expect(ids(failures(rs, b))).toEqual(['PS-01']);
  });

  it('blocks when the price waterfall is incomplete', () => {
    const c = good();
    c.priceWaterfall = { missingCount: '1', missingProperties: ['distributor_margin'] };
    expect(failures(rs, c)[0]).toMatchObject({ ruleId: 'PS-02', params: { missingProperties: 'distributor_margin' } });
  });

  it('blocks and requires re-approval when a price change is above the threshold', () => {
    const c = good();
    c.repricing.maxMaterialPriceChangePct = '5.01';
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['PS-04']);
    expect(f[0]?.remedyKey).toBe('decision.remedy.reapprove_cost');
    expect(f[0]?.params).toEqual({ maxChangePct: '5.01', thresholdPct: '5', materialIds: 'm1' });
  });

  it('blocks with "configure threshold" when no threshold is configured (no default)', () => {
    const c = good();
    delete c.repricing.thresholdPct;
    const f = failures(rs, c);
    expect(ids(f)).toEqual(['PS-03', 'PS-04']);
    expect(f[0]).toMatchObject({ remedyKey: 'decision.remedy.configure_price_change_threshold', reason: 'unmet' });
    expect(f[1]).toMatchObject({ reason: 'missing', missingFields: ['repricing.thresholdPct'] });
    const r = evaluateDecision(rs, c);
    expect(r.ok && r.value.decision).toBe('blocked');
  });

  it('blocks when sections are missing', () => {
    const base = good() as unknown as Record<string, unknown>;
    for (const section of Object.keys(base)) {
      const c = { ...base };
      delete c[section];
      const r = evaluateDecision(rs, c as DecisionContext);
      expect(r.ok && r.value.decision).toBe('blocked');
    }
  });
});

describe('owner-approved decision rule sets (2026-11-21)', () => {
  // A published rule set never changes: any edit must ship as a new version, so its hash is pinned here.
  const pinned: Record<string, { json: unknown; hash: string }> = {
    'approve-formula-version': { json: approveJson, hash: 'fc009c90a8083cab8743f48e4b2f1fa748793758567afcd39d668272fe5c500e' },
    'release-batch': { json: releaseJson, hash: '97296862475a08d0db08e067f4a7c84e068be28f42b0c00757c2a2624f7bb06e' },
    'publish-to-sanad': { json: publishJson, hash: '65caf64f734c4a54a63e50edfe2f839757bbe59e9d792bf69ede2bfda2d0d926' },
  };

  for (const [id, { json, hash }] of Object.entries(pinned)) {
    it(`${id} is published, approved by the owner on 2026-11-21 and its hash is pinned`, () => {
      const rs = load(json);
      expect(rs).toMatchObject({ id, version: '1.0.0', status: 'published', approvedBy: 'owner', approvedOn: '2026-11-21' });
      expect(hashDecisionRuleSet(rs)).toBe(hash);
    });
  }

  it('re-approval threshold comes from the input: 5 passes at 5 and 4.99, blocks at 5.01, and a different input moves the limit', () => {
    const rs = load(publishJson);
    const ctx = (change: string, threshold: string): PublishToSanadContext => ({
      cost: { approved: true, approvedUnitCost: '10' },
      priceWaterfall: { missingCount: '0', missingProperties: [] },
      repricing: { maxMaterialPriceChangePct: change, changedMaterialIds: ['m1'], thresholdPct: threshold },
    });
    expect(ids(failures(rs, ctx('5', '5')))).toEqual([]);
    expect(ids(failures(rs, ctx('4.99', '5')))).toEqual([]);
    expect(ids(failures(rs, ctx('5.01', '5')))).toEqual(['PS-04']);
    expect(ids(failures(rs, ctx('5.01', '10')))).toEqual([]);
  });

  it('no rule carries the threshold as a literal: PS-04 reads it from the context', () => {
    const rs = load(publishJson);
    const ps04 = rs.rules.find((r) => r.id === 'PS-04');
    expect(ps04).toMatchObject({ operator: 'lte', valueFrom: 'repricing.thresholdPct' });
    expect(ps04).not.toHaveProperty('value');
  });
});
