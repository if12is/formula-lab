/** Outcome of a limit check. UNKNOWN (data missing) is never reported as PASS. */
export type CheckStatus = 'PASS' | 'FAIL' | 'UNKNOWN';
