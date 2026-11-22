/**
 * Full perfume cost (L-10): oil + alcohol (landed) + process loss + glass bottle + sprayer + box, per selling unit.
 * Nothing is a second cost model: the batch is made by fineFragrance (masses of 1 kg of product), the material
 * cost by productCostBreakdown (landed EGP/kg prices, the total process loss of L-04 as its wastage), the bottle,
 * sprayer and box by packagingCostPerUnit and the sum by computeUnitCost.
 *
 * - Every price is a sourced, dated value (money per kg for materials, per piece for packaging); water has a price too.
 * - The three packaging slots are required: a perfume without a sprayer is a gap, not a cheaper perfume.
 * - Conversion, quality and one-time costs are optional here. When `rest` is absent the result is the DIRECT cost
 *   (the six components above) and says so with the warning `fragrance.cost_excludes_conversion_quality_one_time`.
 * - The loss shows as its own component: product cost with loss minus product cost without it.
 */
import type { MissingData, SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import type { FxRate } from '../core/fx';
import { qty } from '../core/quantity';
import { all, flatMap, missing, ok, type Result } from '../core/result';
import { computeUnitCost, type UnitCost, type UnitCostInput } from '../costing/unit-cost';
import { materialCostPerKg } from '../cost/material';
import { productCostBreakdown } from '../costing/product-cost';
import type { PackagingBomItem } from '../packaging/bom';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { fineFragrance, type FineFragrance, type FineFragranceInput } from './fine-fragrance';
import { processLoss, type LossStage, type ProcessLoss } from './process-loss';
import type { SourcedScalar } from '../core/parse';
import type { PackingConfig } from '../core/count-units';

export const PERFUME_COST_REASONS = {
  PACKAGING_ITEM_MISSING: 'fragrance.packaging_item_missing',
} as const;

export const PERFUME_COST_WARNINGS = {
  EXCLUDES_REST: 'fragrance.cost_excludes_conversion_quality_one_time',
} as const;

export const PERFUME_PACKAGING_SLOTS = ['bottle', 'sprayer', 'box'] as const;
export type PerfumePackagingSlot = (typeof PERFUME_PACKAGING_SLOTS)[number];

/** Item kinds the slots carry on their cost lines (a kind already on the item is kept). */
const SLOT_KIND: Record<PerfumePackagingSlot, string> = { bottle: 'container', sprayer: 'sprayer', box: 'box' };

/**
 * One packaging slot: the price of ONE piece and its reject %. `qtyPerUnit` is omitted for the usual case (one bottle, one
 * sprayer, one box per perfume): it is then 1, a definition rather than a measurement, carrying the price's own source.
 */
export type PerfumePackagingSlotInput = Omit<PackagingBomItem, 'level' | 'itemId'> & { itemId?: string };

export interface PerfumeCostInput {
  /** The batch: oil, fixative, water, ethanol source and target, optional IFRA limit. Costed per 1 kg of product. */
  fine: Omit<FineFragranceInput, 'batch' | 'policy' | 'productDensity' | 'table'>;
  /** Landed price per kg of each material. `fixative` is needed only when the batch has a fixative. */
  prices: { oil?: SourcedValue; alcohol?: SourcedValue; water?: SourcedValue; fixative?: SourcedValue };
  /** Loss % per stage from the user's trials (L-04). */
  loss: Partial<Record<LossStage, SourcedScalar>>;
  /** Measured density of the finished perfume (turns the fill volume into mass). */
  productDensity?: SourcedValue;
  /** Fill per bottle (nominal + overfill) as one sourced volume or mass. */
  fill?: SourcedValue;
  packaging: Partial<Record<PerfumePackagingSlot, PerfumePackagingSlotInput>> & { extras?: readonly PackagingBomItem[]; packing?: PackingConfig };
  /** Conversion, quality and one-time costs, when the caller wants the full unit cost. */
  rest?: Pick<UnitCostInput, 'conversion' | 'quality' | 'oneTime'>;
  fxRates?: readonly FxRate[];
  policy: ConfidencePolicy;
}

export interface PerfumeCostComponent {
  key: 'oil' | 'alcohol' | 'water' | 'fixative' | 'loss' | PerfumePackagingSlot;
  /** EGP per selling unit. */
  perUnit: Traced;
}

export interface PerfumeCost {
  fine: FineFragrance;
  loss: ProcessLoss;
  /** Oil, alcohol, water, fixative (before loss), loss, bottle, sprayer, box, in this order. */
  components: PerfumeCostComponent[];
  /** Σ of `components`: the direct cost of the six items, EGP per unit. */
  directTotal: Traced;
  unitCost: UnitCost;
}

const EMPTY_REST: NonNullable<PerfumeCostInput['rest']> = {
  conversion: { lines: [], overheads: [] },
  quality: { batchTests: [], regulatory: [] },
  oneTime: [],
};

const ONE_KG = qty('1', 'kg');

export function perfumeCost(input: PerfumeCostInput): Result<PerfumeCost> {
  const oilId = input.fine.fragranceId ?? 'fragrance_oil';
  const alcoholId = input.fine.alcoholMaterialId ?? 'ethanol';
  const hasFixative = input.fine.fixativePct !== undefined && dec(input.fine.fixativePct).gt(0);

  const slotGaps: MissingData[] = PERFUME_PACKAGING_SLOTS.filter((slot) => input.packaging[slot] === undefined).map((slot) => ({
    property: `packaging.${slot}`,
    reason: PERFUME_COST_REASONS.PACKAGING_ITEM_MISSING,
  }));
  const parts = all([
    fineFragrance({ ...input.fine, batch: { mass: ONE_KG }, policy: input.policy }),
    processLoss({ stages: input.loss, policy: input.policy }),
    slotGaps.length > 0 ? missing<true>(slotGaps) : ok(true as const),
  ] as const);
  return flatMap(parts, ([fine, loss]): Result<PerfumeCost> => {
    const per1000g = (t: Traced) => decOf(t).div(10).toFixed(); // % w/w of 1 kg of product
    const lines = [
      { materialId: oilId, percentWW: per1000g(fine.fragranceOilMass) },
      { materialId: alcoholId, percentWW: per1000g(fine.alcoholMass) },
      { materialId: 'water', percentWW: per1000g(fine.waterMass) },
      ...(hasFixative && fine.fixativeMass !== undefined ? [{ materialId: 'fixative', percentWW: per1000g(fine.fixativeMass) }] : []),
    ];
    const priceOf = (id: string): SourcedValue | undefined =>
      id === oilId ? input.prices.oil : id === alcoholId ? input.prices.alcohol : id === 'water' ? input.prices.water : input.prices.fixative;
    const prices = Object.fromEntries(lines.flatMap((l) => (priceOf(l.materialId) === undefined ? [] : [[l.materialId, priceOf(l.materialId) as SourcedValue]])));
    const wastage = decOf(loss.totalLossPct).toFixed();
    const money = { policy: input.policy, ...(input.fxRates !== undefined && { fxRates: input.fxRates }) };
    const net = productCostBreakdown({ lines, prices, ...money }, wastage);
    const gross = materialCostPerKg({ lines, prices, ...money });
    if (!net.ok || !gross.ok) {
      // A missing price: every line without one is listed by the cost module.
      return missing(!net.ok ? net.missing : (gross as { missing: MissingData[] }).missing, !net.ok ? net.warnings : gross.warnings);
    }
    const slotIdOf = (slot: PerfumePackagingSlot): string => input.packaging[slot]?.itemId ?? slot;
    const slotItems = PERFUME_PACKAGING_SLOTS.map((slot): PackagingBomItem => {
      const item = input.packaging[slot] as PerfumePackagingSlotInput;
      const price = item.price;
      const qtyPerUnit = item.qtyPerUnit ?? (price === undefined ? undefined : { value: '1', sourceId: price.sourceId, confidence: price.confidence, validFrom: price.validFrom });
      return { ...item, itemId: slotIdOf(slot), level: 'unit', kind: item.kind ?? SLOT_KIND[slot], ...(qtyPerUnit !== undefined && { qtyPerUnit }) };
    });
    const items = [...slotItems, ...(input.packaging.extras ?? [])];
    const rest = input.rest ?? EMPTY_REST;
    const unitInput: UnitCostInput = {
      policy: input.policy,
      ...(input.fxRates !== undefined && { fxRates: input.fxRates }),
      product: { costPerKg: net.value.costPerKg, materials: net.value.contributions },
      ...(input.fill !== undefined && { fillActual: input.fill }),
      ...(input.productDensity !== undefined && { productDensity: input.productDensity }),
      packaging: { items, ...(input.packaging.packing !== undefined && { packing: input.packaging.packing }) },
      ...rest,
    };
    // A slot without a price reports the price only: its piece count is 1 by definition and not a second gap.
    const noPrice = new Set(PERFUME_PACKAGING_SLOTS.filter((slot) => input.packaging[slot]?.price === undefined).map(slotIdOf));
    const costed = computeUnitCost(unitInput);
    const unitResult: Result<UnitCost> = costed.ok ? costed : missing(costed.missing.filter((m) => !(m.property === 'qtyPerUnit' && m.materialId !== undefined && noPrice.has(m.materialId))), costed.warnings);
    const result = flatMap(unitResult, (unitCost): Result<PerfumeCost> => {
      const yieldValue = decOf(loss.yield);
      const gross1 = (id: string, key: PerfumeCostComponent['key']): PerfumeCostComponent[] => {
        const line = unitCost.materialLines.find((l) => l.key === `product:${id}`);
        return line === undefined ? [] : [{ key, perUnit: traced('mul', `${key} per unit before loss = with-loss share x yield`, decOf(line.perUnit).mul(yieldValue), 'EGP', [line.perUnit, loss.yield]) }];
      };
      const materials = [...gross1(oilId, 'oil'), ...gross1(alcoholId, 'alcohol'), ...gross1('water', 'water'), ...gross1('fixative', 'fixative')];
      const productWithLoss = unitCost.sections.product;
      const lossCost = traced('sub', 'Loss allowance per unit = product with loss - product before loss', decOf(productWithLoss).minus(materials.reduce((a, c) => a.plus(decOf(c.perUnit)), dec('0'))), 'EGP', [productWithLoss, ...materials.map((m) => m.perUnit)]);
      const packagingComponents = PERFUME_PACKAGING_SLOTS.flatMap((slot): PerfumeCostComponent[] => {
        const line = unitCost.lines.find((l) => l.key === `packaging:${slotIdOf(slot)}`);
        return line === undefined ? [] : [{ key: slot, perUnit: line.perUnit }];
      });
      const components = [...materials, { key: 'loss' as const, perUnit: lossCost }, ...packagingComponents];
      const directTotal = traced('sum', 'Direct perfume cost per unit = oil + alcohol + water + fixative + loss + bottle + sprayer + box', components.reduce((a, c) => a.plus(decOf(c.perUnit)), dec('0')), 'EGP', components.map((c) => c.perUnit));
      return ok({ fine, loss, components, directTotal, unitCost }, input.rest === undefined ? [PERFUME_COST_WARNINGS.EXCLUDES_REST] : []);
    });
    // Without `rest` the empty conversion / quality / one-time sections are the stated scope, already said by EXCLUDES_REST.
    return input.rest === undefined ? { ...result, warnings: result.warnings.filter((w) => !w.startsWith('costing.section_empty')) } : result;
  });
}