import { execSync } from 'node:child_process';
import { E2E_DATABASE, E2E_DEMO_SEEDERS, e2eServerEnv } from './e2e-env';

/**
 * Every `pnpm e2e` starts from the same database: migrate:fresh --seed, then the demo seeders. It only ever touches the
 * disposable E2E database (never the dev one): the name is forced here and checked against what Laravel really connected to.
 */
export default function globalSetup(): void {
  const env = { ...process.env, ...e2eServerEnv(), APP_ENV: 'local' };
  const artisan = (command: string): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8', env });

  const connected = artisan(`tinker --execute='echo DB::connection()->getDatabaseName();'`).trim();
  if (connected !== E2E_DATABASE || !connected.endsWith('_e2e')) {
    throw new Error(`Refusing to wipe "${connected}": the e2e run only resets ${E2E_DATABASE}.`);
  }

  artisan('migrate:fresh --seed --force');
  for (const seeder of E2E_DEMO_SEEDERS) {
    artisan(`db:seed --class=${seeder} --force`);
  }
}
