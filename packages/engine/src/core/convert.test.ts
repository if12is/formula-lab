import { describe, expect, it } from 'vitest';
import { massToVolume, volumeToMass, type DensityRef } from './convert';
import { qty, UnitMismatchError } from './quantity';

const water: DensityRef = { value: '1', unit: 'g/mL', sourceId: 'src:water', confidence: 'L3_standard' };
const glycerin: DensityRef = { value: '1.26', unit: 'kg/L', sourceId: 'src:gly', confidence: 'L2_supplier_tds' };

describe('mass<->volume needs explicit density', () => {
  it('no density => missing, never a default', () => {
    for (const r of [
      massToVolume(qty('1', 'kg'), undefined, 'L', { materialId: 'm1' }),
      volumeToMass(qty('1', 'L'), undefined),
    ]) {
      expect(r.ok).toBe(false);
      if (!r.ok) {
        expect(r.missing[0]?.property).toBe('density');
      }
    }
    const r = massToVolume(qty('1', 'kg'), undefined, 'L', { materialId: 'm1' });
    expect(!r.ok && r.missing[0]?.materialId).toBe('m1');
  });
  it('non-positive density => missing', () => {
    const r = massToVolume(qty('1', 'kg'), { ...water, value: '0' });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing[0]?.reason).toMatch(/positive/);
  });
  it('converts with density', () => {
    const v = massToVolume(qty('1.26', 'kg'), glycerin, 'L');
    expect(v.ok && v.value.toJSON()).toEqual({ value: '1', unit: 'L' });
    const m = volumeToMass(qty('500', 'mL'), glycerin, 'kg');
    expect(m.ok && m.value.toJSON()).toEqual({ value: '0.63', unit: 'kg' });
    const dflt = massToVolume(qty('250', 'g'), water);
    expect(dflt.ok && dflt.value.toJSON()).toEqual({ value: '250', unit: 'mL' });
    const dm = volumeToMass(qty('2', 'L'), water);
    expect(dm.ok && dm.value.toJSON()).toEqual({ value: '2000', unit: 'g' });
  });
  it('rejects wrong dimensions', () => {
    expect(() => massToVolume(qty('1', 'L') as never, water)).toThrow(UnitMismatchError);
    expect(() => volumeToMass(qty('1', 'kg') as never, water)).toThrow(UnitMismatchError);
  });
  it('there is no direct L<->kg path', () => {
    expect(() => qty('1', 'L').to('kg' as never)).toThrow(UnitMismatchError);
    expect(() => qty('1', 'kg').add(qty('1', 'L'))).toThrow(UnitMismatchError);
  });
});
