import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';
import type { EngineInput, EngineOutput } from './contract';

/**
 * Canonical JSON: keys sorted recursively, no whitespace. JS numbers are rejected
 * (numbers must travel as decimal strings); undefined object members are skipped like JSON.
 */
export function canonicalJson(value: unknown): string {
  if (value === null) {
    return 'null';
  }
  switch (typeof value) {
    case 'string':
    case 'boolean':
      return JSON.stringify(value);
    case 'number':
      throw new TypeError('canonicalJson: JS numbers are not allowed, use decimal strings');
    case 'object':
      break;
    default:
      throw new TypeError(`canonicalJson: unsupported type ${typeof value}`);
  }
  if (Array.isArray(value)) {
    return `[${value.map((v) => canonicalJson(v)).join(',')}]`;
  }
  const proto = Object.getPrototypeOf(value) as unknown;
  if (proto !== Object.prototype && proto !== null) {
    throw new TypeError('canonicalJson: only plain objects are allowed (call toJSON() first)');
  }
  const record = value as Record<string, unknown>;
  const parts: string[] = [];
  for (const key of Object.keys(record).sort()) {
    const v = record[key];
    if (v !== undefined) {
      parts.push(`${JSON.stringify(key)}:${canonicalJson(v)}`);
    }
  }
  return `{${parts.join(',')}}`;
}

export function sha256Hex(text: string): string {
  return bytesToHex(sha256(utf8ToBytes(text)));
}

/** SHA-256 over the canonical JSON of the input. Same hash => same output. */
export function inputHash(input: EngineInput): string {
  return sha256Hex(canonicalJson(input));
}

/** SHA-256 over the canonical JSON of the output, excluding the outputHash field itself. */
export function outputHash(output: Omit<EngineOutput, 'outputHash'> & { outputHash?: string }): string {
  const rest: Record<string, unknown> = { ...output };
  delete rest.outputHash;
  return sha256Hex(canonicalJson(rest));
}
