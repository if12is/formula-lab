import { defineConfig, devices } from '@playwright/test';
import { E2E_GATE_PORT, E2E_PORT, E2E_PROVIDER_PORT, e2eServerEnv } from './e2e/support/e2e-env';

const EXCLUSIVE = [/j09-n08-evidence\.spec\.ts/, /currency\.spec\.ts/];

// The spec workers shell out to artisan (seeders, tinker) too: they must hit the same disposable database as the server.
Object.assign(process.env, e2eServerEnv());

export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/support/global-setup.ts',
  fullyParallel: true,
  reporter: 'list',
  use: { baseURL: `http://127.0.0.1:${E2E_PORT}`, trace: 'off' },
  // One database serves every worker. A spec that rewrites rows other specs read runs alone, after everything else, in a chain:
  // currency.spec wipes and re-adds every exchange rate (the tiers/ladder/home numbers read the USD rate), and j09-n08-evidence
  // clears and rewrites the EDA checklist entries that j09-n08.spec walks through. Run in parallel they gave wrong costs and missing validation.
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] }, testIgnore: EXCLUSIVE },
    { name: 'exclusive-eda', use: { ...devices['Desktop Chrome'] }, testMatch: /j09-n08-evidence\.spec\.ts/, dependencies: ['chromium'] },
    { name: 'exclusive-rates', use: { ...devices['Desktop Chrome'] }, testMatch: /currency\.spec\.ts/, dependencies: ['exclusive-eda'] },
  ],
  webServer: [
    {
      // The scripted model provider the assistant specs talk to (P-08, P-10).
      command: `node e2e/support/anthropic-stub.mjs ${E2E_PROVIDER_PORT}`,
      url: `http://127.0.0.1:${E2E_PROVIDER_PORT}/up`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      command: `pnpm --filter @fl/api build && php artisan serve --host 127.0.0.1 --port ${E2E_PORT}`,
      cwd: 'apps/api',
      url: `http://127.0.0.1:${E2E_PORT}`,
      // A reused server could point at another database: the clean-DB run always starts its own.
      reuseExistingServer: false,
      env: e2eServerEnv(),
      timeout: 180_000,
    },
    {
      // Same app and database with the terms-of-use gate on (S-16). The build above has finished before it is needed.
      command: `php artisan serve --host 127.0.0.1 --port ${E2E_GATE_PORT}`,
      cwd: 'apps/api',
      url: `http://127.0.0.1:${E2E_GATE_PORT}/up`,
      reuseExistingServer: false,
      env: { ...e2eServerEnv(), TERMS_GATE_ENFORCED: 'true' },
      timeout: 180_000,
    },
  ],
});
