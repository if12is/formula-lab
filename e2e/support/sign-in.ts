import { execSync } from 'node:child_process';
import type { BrowserContext } from '@playwright/test';
import { E2E_OVERRIDE_ROLE } from './e2e-env';

/** TEST-FIXTURE password of the e2e user (not a secret: the e2e database is disposable). */
export const E2E_PASSWORD = 'e2e-password-123';

const tinker = (php: string): string => execSync(`php artisan tinker --execute='${php}'`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' }).trim();

/**
 * Signs a user in without a login screen (there is none until U-01): creates the user with the override role, writes a database
 * session for them and hands the browser the encrypted session cookie Laravel would have issued. Only the disposable e2e database.
 */
export async function signInAsQualityManager(context: BrowserContext, run: string): Promise<string> {
  const email = `e2e-${run}@example.test`;
  const id = tinker(`echo App\\Models\\User::query()->create(["name" => "E2E Quality ${run}", "email" => "${email}", "password" => "${E2E_PASSWORD}", "role" => "${E2E_OVERRIDE_ROLE}"])->id;`);
  const cookie = tinker(
    `$sid = Illuminate\\Support\\Str::random(40); Illuminate\\Support\\Facades\\DB::table("sessions")->insert(["id" => $sid, "user_id" => ${id}, "ip_address" => "127.0.0.1", "user_agent" => "e2e", "payload" => base64_encode(serialize(["login_web_" . sha1(Illuminate\\Auth\\SessionGuard::class) => ${id}])), "last_activity" => time()]); ` +
      `echo config("session.cookie") . "=" . Illuminate\\Support\\Facades\\Crypt::encryptString(Illuminate\\Cookie\\CookieValuePrefix::create(config("session.cookie"), Illuminate\\Support\\Facades\\Crypt::getKey()) . $sid);`,
  );
  const [name, value] = [cookie.slice(0, cookie.indexOf('=')), cookie.slice(cookie.indexOf('=') + 1)];
  await context.addCookies([{ name, value, domain: '127.0.0.1', path: '/', httpOnly: true, sameSite: 'Lax' }]);

  return email;
}
