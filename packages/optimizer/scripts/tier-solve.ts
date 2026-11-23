/**
 * The tier optimizer for the tiers screen (S-05): the app sends a profile, each material's price QUOTES with the dated exchange
 * rates and its active matter, and gets the optimizer's own answer (formula, cost, binding constraints, duals, conflicts, reverse
 * pricing, sensitivity scenarios) plus the materials it was computed without. Prices are picked and converted by the engine
 * (`offeredMaterials`), the same way the cost report does. The Laravel app calls this through App\Support\Engine\EngineRunner.
 *
 * stdin : JSON `{ "profile", "profileSourceId"?, "materials": [{ materialId, quotes, activeMatter?, functions? }], "fxRates"?, "qsMaterialId"?,
 *                 "policy"?, "reversePricing"?, "scenarios"?, "tax"? }`
 *         `profileSourceId` is the one source every written figure of the profile cites; `tax` ({ setting, regimes, productFamilyId, taxType, asOf })
 *         is the product's tax, resolved by the engine into the VAT rate of the reverse pricing.
 * stdout: JSON OptimizerOutput plus `{ "excluded": [{ materialId, property, reason }], "taxMissing": [MissingData] }`
 * Run   : (cwd packages/engine) node --experimental-transform-types --import ./scripts/node-ts/register.mjs ../optimizer/scripts/tier-solve.ts
 * Exit 0 for any answer; non-zero only when the input is not JSON or lacks a profile.
 */
import { resolveProductTax, type FxRate, type MissingData, type TaxRegime, type TaxSetting } from '@fl/engine';
import { offeredMaterials, withProfileSource, type MaterialQuotes } from '../src/app-input';
import { optimize, type OptimizerInput } from '../src/optimize';
import type { TierProfile } from '../src/profile';

async function readStdin(): Promise<string> {
  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks).toString('utf8');
}

interface Body extends Omit<OptimizerInput, 'materials'> {
  materials?: MaterialQuotes[];
  fxRates?: FxRate[];
  profileSourceId?: string | null;
  tax?: { setting?: TaxSetting; regimes: TaxRegime[]; productFamilyId: string; taxType: string; asOf: string };
}

let input: Body;
try {
  input = JSON.parse(await readStdin()) as Body;
} catch {
  console.error('tier-solve: stdin is not valid JSON.');
  process.exit(2);
}
if (typeof input !== 'object' || input === null || !('profile' in input)) {
  console.error('tier-solve: expected {"profile": ..., "materials": [...]} on stdin.');
  process.exit(2);
}

const { materials, excluded } = offeredMaterials(input.materials ?? [], input.fxRates ?? []);
const { fxRates: _fx, materials: _m, profileSourceId, tax, profile, ...rest } = input;
void _fx;
void _m;

let reversePricing = rest.reversePricing;
const taxMissing: MissingData[] = [];
if (reversePricing !== undefined && tax !== undefined) {
  const resolved = resolveProductTax(tax.setting, tax.regimes, tax, {});
  if (resolved.ok) {
    // Tax switched off: there is no tax line, so the shelf price is the price without tax (rate 0, stated by the setting).
    reversePricing = { ...reversePricing, vatRatePct: resolved.value.state === 'disabled' ? '0' : resolved.value.regime.ratePct };
  } else {
    taxMissing.push(...resolved.missing);
  }
}

let sourced: unknown = profile;
try {
  sourced = withProfileSource(profile as TierProfile, profileSourceId ?? null);
} catch {
  // A body that is not even shaped like a profile goes to the validator as it is.
}
const output = await optimize({ ...rest, profile: sourced, materials, ...(reversePricing !== undefined && { reversePricing }) });
process.stdout.write(JSON.stringify({ ...output, excluded, taxMissing }));
