import { defineConfig, devices } from '@playwright/test';
import { e2eServerEnv } from './e2e/support/e2e-env';

// The spec workers shell out to artisan (seeders, tinker) too: they must hit the same disposable database as the server.
Object.assign(process.env, e2eServerEnv());

export default defineConfig({
  testDir: 'e2e',
  globalSetup: './e2e/support/global-setup.ts',
  fullyParallel: true,
  reporter: 'list',
  use: { baseURL: 'http://127.0.0.1:8000', trace: 'off' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'pnpm --filter @fl/api build && php artisan serve --host 127.0.0.1 --port 8000',
    cwd: 'apps/api',
    url: 'http://127.0.0.1:8000',
    // A reused server could point at another database: the clean-DB run always starts its own.
    reuseExistingServer: false,
    env: e2eServerEnv(),
    timeout: 180_000,
  },
});
