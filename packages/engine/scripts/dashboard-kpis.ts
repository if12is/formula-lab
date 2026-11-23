/**
 * The decision figures of the home dashboard (S-15), by the engine's own functions (src/dashboard/kpis.ts). The Laravel app gathers the
 * facts (the newest price date of each material, each formula's cost-report input, what it was approved at, its published price, its
 * compliance input) and calls this from App\Support\Engine\EngineRunner; it only counts and shows the answer.
 *
 * stdin : JSON `{ "asOf", "stalePriceDays": string|null, "materials": [{ materialId, latestQuotedOn }],
 *                 "formulas": [{ id, costReport: CostReportInput, approved?: { materialPrices, unitCost }, sellPrice?,
 *                                compliance?: { materials, ruleSets, context, ifra?: { category?, fragrances: [{ materialId, limits }] } } }] }`
 *         the compliance check reads the formula's lines from the cost report (a q.s. line is balanced there), so the shares are the ones costed
 * stdout: JSON `{ "stale": Result<StalePrices>, "formulas": [{ id, ok, quality, impact, margin, compliance }] }`
 *         where a Result is `{ ok: true, value, warnings }` or `{ ok: false, missing, warnings }`; `impact` and `margin` are null when the
 *         formula has no approved cost to compare with
 * Run   : node --experimental-transform-types --import ./scripts/node-ts/register.mjs scripts/dashboard-kpis.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks `asOf`.
 */
import { buildCostReport, type CostReportInput } from '../src/costing/cost-report';
import type { MaterialComposition } from '../src/compliance/composition';
import type { RuleSet } from '../src/compliance/dsl';
import type { ProductContext } from '../src/compliance/evaluate';
import { ifraLimitsByCategory, type IfraLimitRow } from '../src/compliance/ifra';
import { costQuality, formulaCompliance, marginBound, priceImpact, stalePrices, type StaleMaterialInput } from '../src/dashboard/kpis';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

interface ComplianceFacts {
  /** The compositions of the materials on the formula (a material without one is absent from `composition`). */
  materials: MaterialComposition[];
  ruleSets: RuleSet[];
  context: ProductContext;
  /** The fragrance oils on the formula and the IFRA limits on file for each; absent when it has none. */
  ifra?: { category?: string; fragrances: { materialId: string; limits: IfraLimitRow[] }[] };
}

interface FormulaFacts {
  id: string;
  costReport: CostReportInput;
  approved?: { materialPrices: Record<string, string>; unitCost: string };
  sellPrice?: string | null;
  compliance?: ComplianceFacts;
}

interface Input {
  asOf: string;
  stalePriceDays: string | null;
  materials: StaleMaterialInput[];
  formulas: FormulaFacts[];
}

let input: Input;
try {
  input = JSON.parse(await readStdin()) as Input;
} catch {
  console.error('dashboard-kpis: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || typeof input.asOf !== 'string') {
  console.error('dashboard-kpis: expected {"asOf": "...", "materials": [...], "formulas": [...]} on stdin.');
  process.exit(2);
}

function complianceOf(facts: ComplianceFacts, report: ReturnType<typeof buildCostReport>) {
  const lines = report.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW ?? '' }));
  return formulaCompliance({
    composition: { lines, materials: facts.materials },
    ruleSets: facts.ruleSets,
    context: facts.context,
    ...(facts.ifra !== undefined && {
      ifra: {
        ifraCategory: facts.ifra.category,
        lines,
        fragrances: facts.ifra.fragrances.map((f) => {
          const limitsByCategory = ifraLimitsByCategory(f.limits);
          return Object.keys(limitsByCategory).length > 0 ? { materialId: f.materialId, certificate: { limitsByCategory } } : { materialId: f.materialId };
        }),
      },
    }),
  });
}

const formulas = (input.formulas ?? []).map((facts) => {
  const report = buildCostReport(facts.costReport);
  const quality = costQuality(report, facts.costReport.quotes);
  const impact = facts.approved === undefined
    ? null
    : priceImpact(report.lines.map((l) => ({ materialId: l.materialId, percentWW: l.percentWW ?? '' })), facts.approved.materialPrices, quality.currentPrices);
  const margin = facts.approved === undefined || impact === null
    ? null
    : marginBound({ unitCost: facts.approved.unitCost, sellPrice: facts.sellPrice, materialDeltaPct: impact.ok ? impact.value.deltaPct : null });
  return {
    id: facts.id,
    ok: report.ok,
    quality,
    impact,
    margin,
    compliance: facts.compliance === undefined ? null : complianceOf(facts.compliance, report),
  };
});

process.stdout.write(JSON.stringify({ stale: stalePrices({ asOf: input.asOf, thresholdDays: input.stalePriceDays ?? null, materials: input.materials ?? [] }), formulas }));
