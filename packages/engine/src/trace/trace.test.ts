import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import {
  dedupeMissing, decOf, emptyOutcome, inputLeaf, leavesOf, mergeOutcomes, referenceLeaf, sourcedLeaf, traced,
} from './trace';

const sv: SourcedValue = { value: '10.00', unit: 'EGP', sourceId: 'SRC-1', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' };

describe('trace builders', () => {
  it('normalizes values and keeps the tree', () => {
    const a = sourcedLeaf('MAT-A price', sv);
    const b = inputLeaf('pct', '60.0', '%w/w');
    const t = traced('mul', 'term', '6.00', 'EGP', [a, b]);
    expect(t.value).toBe('6');
    expect(t.trace.inputs).toHaveLength(2);
    expect(t.trace.op).toBe('mul');
    expect(decOf(t).toFixed()).toBe('6');
  });

  it('sourced leaves keep sourceId and confidence; plain leaves have none', () => {
    const leaf = sourcedLeaf('p', sv).trace;
    expect(leaf).toMatchObject({ op: 'input', sourceId: 'SRC-1', confidence: 'L2_supplier_tds', inputs: [] });
    const plain = inputLeaf('x', '1', 'kg').trace;
    expect('sourceId' in plain).toBe(false);
    const ref = referenceLeaf('fx', '48', 'EGP', 'cbe:1').trace;
    expect(ref.sourceId).toBe('cbe:1');
    expect('confidence' in ref).toBe(false);
  });

  it('leavesOf returns every leaf', () => {
    const t = traced('sum', 's', '3', 'EGP', [traced('mul', 'm', '1', 'EGP', [inputLeaf('a', '1', 'EGP')]), inputLeaf('b', '2', 'EGP')]);
    expect(leavesOf(t.trace).map((l) => l.label)).toEqual(['a', 'b']);
  });

  it('rejects non-decimal values', () => {
    expect(() => traced('x', 'x', 'abc', 'EGP')).toThrow(TypeError);
  });
});

describe('outcomes', () => {
  it('dedupes by material+property and merges parts', () => {
    const gap = { materialId: 'A', property: 'price', reason: 'r' };
    expect(dedupeMissing([gap, { ...gap }, { ...gap, reason: 'other' }, { property: 'price', reason: 'r' }])).toHaveLength(3);
    const x = { ...emptyOutcome(), missing: [gap], warnings: ['w1'], results: { k: inputLeaf('a', '1', 'kg') } };
    const y = { ...emptyOutcome(), missing: [gap], warnings: ['w2'], results: { j: inputLeaf('b', '2', 'kg') } };
    const m = mergeOutcomes(x, y);
    expect(Object.keys(m.results)).toEqual(['k', 'j']);
    expect(m.missing).toHaveLength(1);
    expect(m.warnings).toEqual(['w1', 'w2']);
  });
});
