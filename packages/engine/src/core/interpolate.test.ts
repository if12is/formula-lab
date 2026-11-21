import { describe, expect, it } from 'vitest';
import { dec } from './decimal';
import { duplicateXs, interpolateAt, solveForY, sortKnots, type Knot } from './interpolate';

const k = (x: string, y: string): Knot => ({ x: dec(x), y: dec(y) });
const curve = [k('0', '100'), k('1', '1100'), k('2', '5000')];

describe('sortKnots / duplicateXs', () => {
  it('sorts ascending without mutating the input; finds repeated x', () => {
    const input = [k('2', '1'), k('0', '2'), k('1', '3'), k('1', '4')];
    const sorted = sortKnots(input);
    expect(sorted.map((p) => p.x.toFixed())).toEqual(['0', '1', '1', '2']);
    expect(input[0]!.x.toFixed()).toBe('2');
    expect(duplicateXs(sorted).map((x) => x.toFixed())).toEqual(['1']);
    expect(duplicateXs(curve)).toEqual([]);
  });
});

describe('interpolateAt', () => {
  it('interpolates linearly: x=0.5 => 100 + 0.5 x 1000 = 600; x=1.5 => 1100 + 0.5 x 3900 = 3050', () => {
    const a = interpolateAt(curve, dec('0.5'));
    expect(a && [a.y.toFixed(), a.lo, a.hi]).toEqual(['600', 0, 1]);
    const b = interpolateAt(curve, dec('1.5'));
    expect(b && [b.y.toFixed(), b.lo, b.hi]).toEqual(['3050', 1, 2]);
  });
  it('a knot returns itself with lo = hi (both ends included)', () => {
    expect(interpolateAt(curve, dec('1'))).toMatchObject({ lo: 1, hi: 1 });
    expect(interpolateAt(curve, dec('0'))?.y.toFixed()).toBe('100');
    expect(interpolateAt(curve, dec('2'))?.y.toFixed()).toBe('5000');
  });
  it('never extrapolates; no knots => null', () => {
    expect(interpolateAt(curve, dec('-0.01'))).toBeNull();
    expect(interpolateAt(curve, dec('2.01'))).toBeNull();
    expect(interpolateAt([], dec('1'))).toBeNull();
  });
});

describe('solveForY', () => {
  it('inverts a rising curve: y=600 => x=0.5 (monotonic)', () => {
    const r = solveForY(curve, dec('600'));
    expect(r.monotonic).toBe(true);
    expect(r.solutions.map((s) => [s.x.toFixed(), s.lo, s.hi])).toEqual([['0.5', 0, 1]]);
  });
  it('a target equal to a shared knot gives one solution', () => {
    expect(solveForY(curve, dec('1100')).solutions.map((s) => s.x.toFixed())).toEqual(['1']);
    expect(solveForY(curve, dec('100')).solutions.map((s) => s.x.toFixed())).toEqual(['0']);
    expect(solveForY(curve, dec('5000')).solutions.map((s) => s.x.toFixed())).toEqual(['2']);
  });
  it('rise-and-fall curve returns every x, ascending, flagged non-monotonic', () => {
    // 0->0, 1->10, 2->0: y=5 at x=0.5 and x=1.5
    const r = solveForY([k('0', '0'), k('1', '10'), k('2', '0')], dec('5'));
    expect(r.monotonic).toBe(false);
    expect(r.solutions.map((s) => s.x.toFixed())).toEqual(['0.5', '1.5']);
  });
  it('a flat segment at the target is an interval and absorbs its end knots', () => {
    // 0->0, 1->5, 2->5, 3->9: y=5 on x in [1,2]; the knots x=1 and x=2 from the neighbours are redundant
    const r = solveForY([k('0', '0'), k('1', '5'), k('2', '5'), k('3', '9')], dec('5'));
    expect(r.solutions.map((s) => [s.x.toFixed(), s.xTo?.toFixed()])).toEqual([['1', '2']]);
  });
  it('target outside the y span => no solutions', () => {
    expect(solveForY(curve, dec('99')).solutions).toEqual([]);
    expect(solveForY(curve, dec('5001')).solutions).toEqual([]);
    expect(solveForY([k('0', '1')], dec('1'))).toEqual({ solutions: [], monotonic: true });
  });
});
