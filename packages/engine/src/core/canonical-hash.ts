import { canonicalJson, sha256Hex } from '../hash';

/** sha256 over the canonical JSON of a value (key order and whitespace do not matter). */
export function canonicalHash(value: unknown): string {
  return sha256Hex(canonicalJson(value));
}
