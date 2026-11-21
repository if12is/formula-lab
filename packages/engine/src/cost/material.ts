import type { SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import { combine, ok, type Result } from '../core/result';
import type { FormulaLine } from '../formula/issues';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';
import { sourcedEgp, type MoneyContext } from './money';

export interface MaterialCostInput extends MoneyContext {
  /** Already-validated lines (Σ = 100). */
  lines: readonly FormulaLine[];
  /** materialId -> dated price, money per kg. */
  prices: Readonly<Record<string, SourcedValue>>;
}

/**
 * C_kg = Σ (pᵢ/100 × Pᵢ), in EGP per kg. EVERY line without a usable price is listed in
 * `missing` (not just the first); nothing is costed as zero.
 */
export function materialCostPerKg(input: MaterialCostInput): Result<Traced> {
  const perLine = input.lines.map((line): Result<Traced> => {
    const price = Object.prototype.hasOwnProperty.call(input.prices, line.materialId)
      ? input.prices[line.materialId]
      : undefined;
    const priced = sourcedEgp(line.materialId, 'price', `${line.materialId} price (EGP/kg)`, price, input);
    if (!priced.ok) {
      return priced;
    }
    const pct = inputLeaf(`${line.materialId} percent`, line.percentWW, '%w/w');
    const term = dec(line.percentWW).div(100).mul(priced.value.value);
    return ok(traced('mul', `${line.materialId} cost = p/100 × P`, term, 'EGP', [pct, priced.value]), priced.warnings);
  });
  const all = combine(perLine);
  if (!all.ok) {
    return all;
  }
  const total = all.value.reduce((acc, t) => acc.plus(decOf(t)), dec('0'));
  return ok(traced('sum', 'Material cost per kg (EGP/kg) = Σ p/100 × P', total, 'EGP', all.value), all.warnings);
}
