import { readFileSync, writeFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { messageKeysJson, OPTIMIZER_MESSAGE_KEYS } from './messages';

const FILE = new URL('../messages.keys.json', import.meta.url);

describe('message keys contract', () => {
  it('messages.keys.json is up to date (run with UPDATE_KEYS=1 to regenerate)', () => {
    if (process.env.UPDATE_KEYS === '1') {
      writeFileSync(FILE, messageKeysJson());
    }
    expect(readFileSync(FILE, 'utf8')).toBe(messageKeysJson());
  });

  it('keys are unique, optimizer-prefixed and sorted into no duplicates', () => {
    const keys = OPTIMIZER_MESSAGE_KEYS.map((s) => s.key);
    expect(new Set(keys).size).toBe(keys.length);
    expect(keys.every((x) => x.startsWith('optimizer.'))).toBe(true);
  });
});
