/**
 * The bridge between the disinfectant notice (N-12) and the engine (@fl/engine). The browser never decides whether a product is on
 * the separate EDA path: disinfectionPathwayNotice does, from the shipped flag data (DISINFECTANT_FLAG). This file only hands it the
 * product's family group and claims and shapes the answer for the screen.
 */
import { DISINFECTANT_FLAG, disinfectionPathwayNotice } from '@fl/engine';

/** The claim codes a product can carry, in the data file's order. */
export const claimCodes = DISINFECTANT_FLAG.claims.map((claim) => claim.code);

/** { ok: true, notice } with the engine's status, triggers and pathway, or { ok: false, missing } for a claim the data does not know. */
export function evaluateDisinfection(familyGroup, claims) {
    const result = disinfectionPathwayNotice(DISINFECTANT_FLAG, { familyGroup: familyGroup ?? null, claims });

    return result.ok ? { ok: true, notice: result.value } : { ok: false, missing: result.missing };
}
