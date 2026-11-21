import { describe, expect, it } from 'vitest';
import { canonicalJson, inputHash, outputHash, sha256Hex } from './hash';
import { ENGINE_VERSION, type EngineInput, type EngineOutput } from './contract';
import { qty } from './core/quantity';

const input: EngineInput = {
  engineVersion: ENGINE_VERSION,
  datasetVersion: 'ds-1',
  formula: { lines: [{ materialId: 'a', percentWW: '60.5' }, { materialId: 'b', percentWW: '39.5' }] },
  prices: {
    a: { value: '10', unit: 'EGP', sourceId: 's', confidence: 'L2_supplier_tds', validFrom: '2026-11-01' },
  },
  policy: { rounding: 'default' },
};

describe('sha256', () => {
  it('matches the known vector for "abc" and empty string', () => {
    expect(sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    expect(sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  });
});

describe('canonicalJson', () => {
  it('sorts keys recursively, no whitespace, skips undefined members', () => {
    expect(canonicalJson({ b: 1 === 1 ? 'x' : 'y', a: { d: [true, null], c: 'é"' }, z: undefined }))
      .toBe('{"a":{"c":"é\\"","d":[true,null]},"b":"x"}');
  });
  it('rejects numbers anywhere, and other unsupported values', () => {
    expect(() => canonicalJson({ a: 1 })).toThrow(/numbers/);
    expect(() => canonicalJson({ a: [{ b: 0.5 }] })).toThrow(TypeError);
    expect(() => canonicalJson(undefined)).toThrow(TypeError);
    expect(() => canonicalJson(10n)).toThrow(TypeError);
    expect(() => canonicalJson([undefined])).toThrow(TypeError);
    expect(() => canonicalJson(qty('1', 'g'))).toThrow(/plain objects/);
  });
  it('accepts null-prototype objects', () => {
    const o = Object.create(null) as Record<string, string>;
    o.k = 'v';
    expect(canonicalJson(o)).toBe('{"k":"v"}');
  });
});

describe('inputHash / outputHash', () => {
  it('is independent of key order', () => {
    const reordered: EngineInput = {
      policy: { rounding: 'default' },
      prices: { a: { validFrom: '2026-11-01', confidence: 'L2_supplier_tds', sourceId: 's', unit: 'EGP', value: '10' } },
      formula: { lines: [{ percentWW: '60.5', materialId: 'a' }, { percentWW: '39.5', materialId: 'b' }] },
      datasetVersion: 'ds-1',
      engineVersion: ENGINE_VERSION,
    };
    expect(inputHash(reordered)).toBe(inputHash(input));
    expect(inputHash(input)).toMatch(/^[0-9a-f]{64}$/);
  });
  it('changes when content changes', () => {
    const other = { ...input, datasetVersion: 'ds-2' };
    expect(inputHash(other)).not.toBe(inputHash(input));
  });
  it('rejects a number smuggled into the input', () => {
    const bad = { ...input, formula: { lines: [{ materialId: 'a', percentWW: 60.5 }] } };
    expect(() => inputHash(bad as unknown as EngineInput)).toThrow(TypeError);
  });
  it('outputHash ignores the outputHash field itself', () => {
    const out: Omit<EngineOutput, 'outputHash'> = {
      ok: true, missing: [], results: {}, warnings: [],
      inputHash: inputHash(input), engineVersion: ENGINE_VERSION, datasetVersion: 'ds-1',
    };
    const h = outputHash(out);
    expect(outputHash({ ...out, outputHash: 'whatever' })).toBe(h);
    expect(outputHash({ ...out, warnings: ['w'] })).not.toBe(h);
  });
});
