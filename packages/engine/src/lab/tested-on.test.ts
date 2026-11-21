import { describe, expect, it } from 'vitest';
import { testedOnTags, testedValuesOf, type RhlbCandidate, type TestedMark } from './tested-on';

const cand = (value: string, confidence: RhlbCandidate['confidence'], sourceId: string, validFrom = '2026-01-01'): RhlbCandidate => ({ value, confidence, sourceId, validFrom });

describe('testedValuesOf', () => {
  it('doses and the rHLB the engine picks: SLES 12 %, water q.s. = 100 - 12 = 88 %; rHLB: L1 beats L2', () => {
    const r = testedValuesOf(
      [
        { materialId: 'sles', percentWW: '12' },
        { materialId: 'water', qs: true },
      ],
      { sles: [cand('10', 'L2_supplier_tds', 's-tds'), cand('11', 'L1_coa_batch', 's-coa')] },
    );
    expect(r.ok && r.value).toEqual([
      { kind: 'dose', materialId: 'sles', value: '12' },
      { kind: 'required_hlb', materialId: 'sles', value: '11', sourceId: 's-coa' },
      { kind: 'dose', materialId: 'water', value: '88' },
    ]);
  });

  it('no required HLB registered: only the dose is recorded (nothing invented)', () => {
    const r = testedValuesOf([{ materialId: 'a', percentWW: '60' }, { materialId: 'b', percentWW: '40' }], {});
    expect(r.ok && r.value.map((v) => `${v.kind}:${v.materialId}:${v.value}`)).toEqual(['dose:a:60', 'dose:b:40']);
  });

  it('same confidence: the newest date wins (rHLB 9 dated 2026-06-01 over 8 dated 2026-01-01)', () => {
    const r = testedValuesOf([{ materialId: 'a', percentWW: '100' }], { a: [cand('8', 'L3_standard', 'x'), cand('9', 'L3_standard', 'y', '2026-06-01')] });
    expect(r.ok && r.value.find((v) => v.kind === 'required_hlb')?.value).toBe('9');
  });

  it('a formula the engine cannot balance or read is missing', () => {
    const noQs = testedValuesOf([{ materialId: 'a', percentWW: 'x' }], {});
    expect(!noQs.ok && noQs.missing[0]).toEqual({ materialId: 'a', property: 'dose', reason: 'formula.percent_invalid' });
    const over = testedValuesOf([{ materialId: 'a', percentWW: '100' }, { materialId: 'b', qs: true }], {});
    expect(!over.ok && over.missing[0]?.reason).toBe('formula.qs_balance_not_positive');
  });
});

const mark = (over: Partial<TestedMark>): TestedMark => ({
  kind: 'required_hlb', materialId: 'sles', value: '11', formulaId: 'f1', formulaCode: 'F-0001', version: 1, trialId: 't1', trialCode: 'F-0001/T1',
  testedOn: '2026-11-10', confidence: 'L1_coa_batch', ...over,
});

describe('testedOnTags', () => {
  it('tags only a value with the same material, kind and number: 11.0 equals 11, 12 does not', () => {
    const marks = [mark({})];
    expect(testedOnTags([{ kind: 'required_hlb', materialId: 'sles', value: '11.0' }], marks)).toHaveLength(1);
    expect(testedOnTags([{ kind: 'required_hlb', materialId: 'sles', value: '12' }], marks)).toEqual([]);
    expect(testedOnTags([{ kind: 'dose', materialId: 'sles', value: '11' }], marks)).toEqual([]);
    expect(testedOnTags([{ kind: 'required_hlb', materialId: 'other', value: '11' }], marks)).toEqual([]);
  });

  it('lists every formula it was tested on, newest first', () => {
    const marks = [
      mark({ formulaCode: 'F-0001', testedOn: '2026-11-10' }),
      mark({ formulaCode: 'F-0002', testedOn: '2026-11-15', trialId: 't2' }),
      mark({ formulaCode: 'F-0003', testedOn: '2026-11-15', version: 2 }),
    ];
    const tags = testedOnTags([{ kind: 'required_hlb', materialId: 'sles', value: '11' }], marks);
    expect(tags[0]?.marks.map((m) => m.formulaCode)).toEqual(['F-0002', 'F-0003', 'F-0001']);
  });

  it('a doses tag per used value; an invalid value is never tagged', () => {
    const marks = [mark({ kind: 'dose', value: '12' })];
    const tags = testedOnTags([{ kind: 'dose', materialId: 'sles', value: '12' }, { kind: 'dose', materialId: 'sles', value: 'abc' }], marks);
    expect(tags.map((t) => t.value)).toEqual(['12']);
  });
});
