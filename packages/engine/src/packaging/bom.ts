/**
 * H-03: packaging BOM cost per unit (plan/00-research/06-cost-drivers.md §3).
 *
 *   per item = price × qty ÷ pieces per level ÷ (1 − reject%/100)
 *   packaging per unit = Σ items
 *
 * `level` says what one BOM quantity is counted against: a piece (`unit`), a shipping carton (`carton`, divided
 * by units per carton) or a pallet (`pallet`, divided by cartons per pallet × units per carton). The packing
 * configuration is explicit; a carton or pallet item without it is `missing`. Reject % is sourced input
 * (H-04) and there is no default: an explicit 0 is a value, an absent one is a gap. Every gap of every
 * item is listed at once.
 */
import type { SourcedValue } from '../contract';
import { cartonsToPieces, palletsToPieces, type PackingConfig } from '../core/count-units';
import { dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import { all, missing, ok, type Result } from '../core/result';
import type { MoneyContext } from '../cost/money';
import { sourcedEgp } from '../cost/money';
import { duplicateIds, lineCurrency, resolveScalar, sumTraced, type CostLine } from '../costing/line';
import { decOf, inputLeaf, traced, type Traced } from '../trace/trace';

export type PackLevel = 'unit' | 'carton' | 'pallet';

export interface PackagingBomItem {
  itemId: string;
  /** Item kind (container, closure, label, shipping_carton, ...), used by the share report. */
  kind?: string;
  level: PackLevel;
  /** Price of ONE item (piece, carton or pallet), dated and sourced; EGP or another currency with a dated rate. */
  price?: SourcedValue;
  /** Items of this kind per level-unit (1 bottle per unit, 1 carton per carton, 0.5 divider per carton ...). */
  qtyPerUnit?: SourcedScalar;
  /** Reject / breakage %, 0 <= r < 100. */
  rejectPct?: SourcedScalar;
}

export interface PackagingBomInput extends MoneyContext {
  items: readonly PackagingBomItem[];
  /** Units per carton / cartons per pallet. Only needed when a carton or pallet item exists. */
  packing?: PackingConfig;
}

export interface PackagingCost {
  lines: CostLine[];
  /** EGP per selling unit. */
  total: Traced;
}

export const PACKAGING_REASONS = {
  DUPLICATE_ITEM: 'packaging.duplicate_item',
  REJECT_OUT_OF_RANGE: 'packaging.reject_out_of_range',
  INVALID_PACKING: 'packaging.invalid_packing_config',
} as const;

/** Pieces per level as a trace leaf; `unit` needs nothing. */
function piecesPerLevel(item: PackagingBomItem, packing: PackingConfig | undefined): Result<Traced | undefined> {
  if (item.level === 'unit') {
    return ok(undefined);
  }
  let pieces: Result<string>;
  try {
    pieces = item.level === 'carton' ? cartonsToPieces('1', packing ?? {}) : palletsToPieces('1', packing ?? {});
  } catch {
    // count-units throws RangeError for a non-positive or non-integer configuration.
    return missing([{ materialId: item.itemId, property: 'packing', reason: PACKAGING_REASONS.INVALID_PACKING }]);
  }
  if (!pieces.ok) {
    return missing(pieces.missing.map((m) => ({ ...m, materialId: item.itemId })));
  }
  return ok(inputLeaf(`Pieces per ${item.level}`, pieces.value, 'pc'));
}

function itemLine(item: PackagingBomItem, input: PackagingBomInput): Result<CostLine> {
  const id = item.itemId;
  const parts = all([
    sourcedEgp(id, 'price', `${id} price (EGP per item)`, item.price, input),
    resolveScalar(id, 'qtyPerUnit', `${id} qty per ${item.level}`, item.qtyPerUnit, 'pc', input, { minExclusive: true }),
    resolveScalar(id, 'rejectPct', `${id} reject %`, item.rejectPct, '%w/w', input, { max: '100' }),
    piecesPerLevel(item, input.packing),
  ] as const);
  if (!parts.ok) {
    return parts;
  }
  const [price, qty, reject, pieces] = parts.value;
  if (reject.value.gte(100)) {
    return missing([{ materialId: id, property: 'rejectPct', reason: PACKAGING_REASONS.REJECT_OUT_OF_RANGE }], parts.warnings);
  }
  let cost = traced('mul', `${id} price × qty`, decOf(price).mul(qty.value), 'EGP', [price, qty.leaf]);
  if (pieces !== undefined) {
    cost = traced('div', `${id} ÷ pieces per ${item.level}`, decOf(cost).div(pieces.value), 'EGP', [cost, pieces]);
  }
  const yieldLeaf = traced('sub', `${id} yield = 1 − reject/100`, dec('1').minus(reject.value.div(100)), '%w/w', [reject.leaf]);
  const perUnit = traced('div', `${id} per unit = ÷ yield (reject allowance)`, decOf(cost).div(yieldLeaf.value), 'EGP', [cost, yieldLeaf]);
  const line: CostLine = { key: `packaging:${id}`, label: `${id} (${item.level})`, perUnit, currency: lineCurrency(item.price as SourcedValue) };
  if (item.kind !== undefined) {
    line.kind = item.kind;
  }
  return ok(line, parts.warnings);
}

/** Packaging cost per selling unit. Gaps of all items are collected; nothing is costed as zero. */
export function packagingCostPerUnit(input: PackagingBomInput): Result<PackagingCost> {
  const dups = duplicateIds(input.items.map((i) => i.itemId), 'itemId', PACKAGING_REASONS.DUPLICATE_ITEM);
  const lines = input.items.map((item) => itemLine(item, input));
  const warnings = lines.flatMap((l) => l.warnings);
  const gaps = [...dups, ...lines.flatMap((l) => (l.ok ? [] : l.missing))];
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  const okLines = lines.map((l) => (l as { ok: true; value: CostLine }).value);
  const total = sumTraced('Packaging cost per unit = Σ items', okLines.map((l) => l.perUnit));
  return ok({ lines: okLines, total }, warnings);
}
