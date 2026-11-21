import { dec } from '../core/decimal';
import { Quantity, UnitMismatchError } from '../core/quantity';
import { inputLeaf, traced, type Traced } from '../trace/trace';
import { formulaFail, formulaOk, type FormulaLine, type FormulaResult } from './issues';
import { validateFormula } from './validate';

export interface ScaledLine {
  materialId: string;
  percentWW: string;
  mass: Traced;
}

export interface ScaledFormula {
  lines: ScaledLine[];
  total: Traced;
  /** batchMass − Σmᵢ: "0" when Σpᵢ is exactly 100; otherwise the mass not allocated (tolerance case). */
  residual: string;
}

/** mᵢ = M × pᵢ/100, in the unit of the batch mass. Validates the formula first. */
export function scaleFormula(
  lines: readonly FormulaLine[],
  batchMass: Quantity,
): FormulaResult<ScaledFormula> {
  if (batchMass.dimension !== 'mass') {
    throw new UnitMismatchError('scaleFormula', batchMass.unit, 'kg');
  }
  const validation = validateFormula(lines);
  if (!validation.ok) {
    return formulaFail(validation.errors);
  }
  const unit = batchMass.unit;
  const batch = inputLeaf('Batch mass', batchMass.value, unit);
  const scaled = lines.map((l): ScaledLine => {
    const pct = inputLeaf(`${l.materialId} percent`, l.percentWW, '%w/w');
    const mass = traced('mul', `${l.materialId} mass = M × p / 100`, batchMass.value.mul(l.percentWW).div(100), unit, [batch, pct]);
    return { materialId: l.materialId, percentWW: l.percentWW, mass };
  });
  const total = scaled.reduce((acc, l) => acc.plus(l.mass.value), dec('0'));
  const residual = batchMass.value.minus(total).toFixed();
  const warnings = residual === '0' ? [] : ['formula.scaled_sum_not_exact'];
  return formulaOk(
    { lines: scaled, total: traced('sum', 'Total of line masses', total, unit, scaled.map((l) => l.mass)), residual },
    warnings,
  );
}
