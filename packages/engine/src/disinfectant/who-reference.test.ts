import { describe, expect, it } from 'vitest';
import reference from '../../data/disinfectant-reference/who-handrub-formulations.json';

describe('WHO handrub reference metadata (not acceptance limits)', () => {
  it('is a sourced reference, not a rule: decimal-string values, a read source with L3 confidence, no limit fields', () => {
    expect(reference.status).toBe('reference');
    expect(reference.source).toMatchObject({ confidence: 'L3_standard', retrievedAt: '2026-11-23' });
    for (const f of reference.formulations) {
      expect(Object.keys(f).sort()).toEqual(['finalConcentrations', 'id']);
      for (const c of f.finalConcentrations) {
        expect(c.value).toMatch(/^\d+(\.\d+)?$/);
        expect(c.unit).toBe('%v/v');
      }
    }
  });
});
