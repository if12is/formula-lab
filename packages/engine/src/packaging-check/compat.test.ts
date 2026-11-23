import { describe, expect, it } from 'vitest';
import { money, scalar } from '../costing/test-fixtures';
import { packagingCostPerUnit, type PackagingBomItem } from '../packaging/bom';
import { checkPackagingCompatibility, neckKey, type CompatItem, type CompatPair } from './compat';

/*
 * Neck finishes and pairs are TEST INPUTS (fictional codes). Hand-checked cases:
 *   1) bottle 24/410 + cap 24/410, pair 24/410 <-> 24/410 declared (L3)            => PASS
 *   2) bottle 24/410 + cap 28/410, the only pair declared is 28/410 <-> 28/410      => FAIL (nobody declared this pair)
 *   3) identical finish text is NOT assumed to fit: pairs [] and 24/410 on both     => FAIL
 *   4) the cap has no neck finish recorded                                          => UNKNOWN, never PASS or FAIL
 *   5) bottle + pump + cap on one bottle, pump pair declared but cap pair not       => FAIL, PASS for the pump kept in checks
 *   6) the only pair is an L7 estimate                                              => UNKNOWN (refused), allowed with the policy
 */
const pair = (containerNeck: string, closureNeck: string, confidence: CompatPair['confidence'] = 'L3_standard'): CompatPair => ({ containerNeck, closureNeck, sourceId: 'TEST-FIXTURE', confidence, validFrom: '2026-01-01' });
const bottle = (neckFinish?: string | null): CompatItem => ({ itemId: 'bottle', kind: 'container', ...(neckFinish === undefined ? {} : { neckFinish }) });
const cap = (neckFinish?: string | null, itemId = 'cap'): CompatItem => ({ itemId, kind: 'closure', ...(neckFinish === undefined ? {} : { neckFinish }) });

describe('checkPackagingCompatibility (H-02)', () => {
  it('1) a declared pair passes and the deciding pair is returned with its source', () => {
    const r = checkPackagingCompatibility([bottle('24/410'), cap('24/410')], [pair('24/410', '24/410')]);
    expect(r.status).toBe('PASS');
    expect(r.checks).toEqual([{ containerId: 'bottle', closureId: 'cap', status: 'PASS', pair: pair('24/410', '24/410') }]);
  });

  it('2) a pair nobody declared fails, even when other pairs exist', () => {
    const r = checkPackagingCompatibility([bottle('24/410'), cap('28/410')], [pair('28/410', '28/410')]);
    expect([r.status, r.checks.map((c) => c.status), r.missing]).toEqual(['FAIL', ['FAIL'], []]);
  });

  it('3) identical finish text is not assumed compatible: with no declared pair it fails', () => {
    expect(checkPackagingCompatibility([bottle('24/410'), cap('24/410')], []).status).toBe('FAIL');
  });

  it('4) a missing neck finish is UNKNOWN and named, on the container and the closure alike', () => {
    const r = checkPackagingCompatibility([bottle(null), cap()], [pair('24/410', '24/410')]);
    expect(r.status).toBe('UNKNOWN');
    expect(r.missing).toEqual([
      { materialId: 'bottle', property: 'neckFinish', reason: 'packaging_compat.neck_finish_missing' },
      { materialId: 'cap', property: 'neckFinish', reason: 'packaging_compat.neck_finish_missing' },
    ]);
  });

  it('5) a pump and a cap on one bottle are each checked; one failing pair fails the whole BOM', () => {
    const r = checkPackagingCompatibility(
      [bottle('24/410'), { itemId: 'pump', kind: 'pump_sprayer', neckFinish: '24/410' }, cap('28/410')],
      [pair('24/410', '24/410'), pair('28/410', '28/410')],
    );
    expect(r.status).toBe('FAIL');
    expect(r.checks.map((c) => [c.closureId, c.status])).toEqual([['pump', 'PASS'], ['cap', 'FAIL']]);
  });

  it('6) a pair resting on an L7 estimate is refused in the final check and allowed by the policy, with a warning', () => {
    const items = [bottle('24/410'), cap('24/410')];
    const refused = checkPackagingCompatibility(items, [pair('24/410', '24/410', 'L7_estimate')]);
    expect([refused.status, refused.missing]).toEqual(['UNKNOWN', [{ materialId: 'cap', property: 'pair', reason: 'packaging_compat.pair_confidence_L7_rejected_by_policy' }]]);
    const allowed = checkPackagingCompatibility(items, [pair('24/410', '24/410', 'L7_estimate')], { allowL7: true });
    expect([allowed.status, allowed.warnings]).toEqual(['PASS', ['packaging_compat.pair_is_estimate:bottle:cap']]);
  });

  it('7) a better pair beats an L7 one for the same finishes', () => {
    const r = checkPackagingCompatibility([bottle('24/410'), cap('24/410')], [pair('24/410', '24/410', 'L7_estimate'), pair('24/410', '24/410', 'L2_supplier_tds')]);
    expect([r.status, r.checks[0]?.pair?.confidence]).toEqual(['PASS', 'L2_supplier_tds']);
  });

  it('8) finish text compares trimmed, single-spaced and without case; the pair has no direction mix-up', () => {
    expect(neckKey('  24 / 410 ')).toBe('24 / 410');
    expect(neckKey('PCO  1881')).toBe('pco 1881');
    expect(neckKey('   ')).toBeNull();
    expect(checkPackagingCompatibility([bottle('PCO 1881'), cap('pco  1881')], [pair(' pco 1881', 'PCO 1881')]).status).toBe('PASS');
    // The pair is container -> closure: the reverse declaration does not satisfy it.
    expect(checkPackagingCompatibility([bottle('24/410'), cap('28/410')], [pair('28/410', '24/410')]).status).toBe('FAIL');
  });

  it('9) a closure with no container is UNKNOWN; a container with no closure passes with a warning; a BOM with neither passes', () => {
    expect(checkPackagingCompatibility([cap('24/410')], [pair('24/410', '24/410')]).missing).toEqual([{ materialId: 'cap', property: 'container', reason: 'packaging_compat.no_container' }]);
    const alone = checkPackagingCompatibility([bottle('24/410')], []);
    expect([alone.status, alone.warnings]).toEqual(['PASS', ['packaging_compat.container_without_closure:bottle']]);
    expect(checkPackagingCompatibility([{ itemId: 'label', kind: 'label' }], []).status).toBe('PASS');
  });

  it('10) an invalid declared pair (no source, empty finish) is reported, never trusted', () => {
    const bad = { containerNeck: '24/410', closureNeck: '', sourceId: '', confidence: 'L3_standard' } as CompatPair;
    const r = checkPackagingCompatibility([bottle('24/410'), cap('24/410')], [bad]);
    expect([r.status, r.missing.map((m) => m.reason)]).toEqual(['UNKNOWN', ['packaging_compat.pair_invalid']]);
  });
});

describe('packagingCostPerUnit with the compatibility constraint (H-02): no BOM with a closure that does not fit', () => {
  const part = (over: Partial<PackagingBomItem> & { itemId: string }): PackagingBomItem => ({ level: 'unit', price: money('1'), qtyPerUnit: scalar('1'), rejectPct: scalar('0'), ...over });
  const bom = (capNeck: string | null) => [
    part({ itemId: 'bottle', kind: 'container', price: money('4'), neckFinish: '24/410' }),
    part({ itemId: 'cap', kind: 'closure', price: money('1'), neckFinish: capNeck }),
  ];

  it('a compatible BOM is costed as before: 4 + 1 = 5', () => {
    const r = packagingCostPerUnit({ policy: {}, items: bom('24/410'), compatibility: { pairs: [pair('24/410', '24/410')] } });
    expect(r.ok && r.value.total.value).toBe('5');
  });

  it('an incompatible closure refuses the whole BOM and costs nothing', () => {
    const r = packagingCostPerUnit({ policy: {}, items: bom('28/410'), compatibility: { pairs: [pair('24/410', '24/410')] } });
    expect(!r.ok && r.missing).toEqual([{ materialId: 'cap', property: 'compatibility', reason: 'packaging_compat.no_declared_pair' }]);
  });

  it('a closure without a neck finish refuses it too, alongside any pricing gap', () => {
    const items = bom(null);
    items[1] = { ...items[1]!, price: undefined };
    const r = packagingCostPerUnit({ policy: {}, items, compatibility: { pairs: [pair('24/410', '24/410')] } });
    expect(!r.ok && r.missing.map((m) => `${m.materialId}:${m.property}`)).toEqual(['cap:price', 'cap:neckFinish']);
  });

  it('without the compatibility input the BOM is costed as it always was (older callers)', () => {
    const r = packagingCostPerUnit({ policy: {}, items: bom('28/410') });
    expect(r.ok && r.value.total.value).toBe('5');
  });
});
