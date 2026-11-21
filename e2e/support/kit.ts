import { execSync } from 'node:child_process';
import { expect, type Locator, type Page } from '@playwright/test';

/** Seeds the demo materials and the three-version formula the formulation specs read (idempotent: skipped when present). */
export function seedFormulationDemo(): void {
  execSync('php artisan db:seed --class=FormulationDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
}

/** A short unique suffix, so rows created by a spec never collide with an earlier run. */
export const stamp = (): string => Date.now().toString(36);

/** Picks an option of a kit Select (a reka listbox) by its visible name. */
export async function pickOption(page: Page, trigger: Locator, name: string | RegExp): Promise<void> {
  await trigger.click();
  await page.getByRole('option', { name }).first().click();
}

/** Types a date into a kit DatePicker (day / month / year spinbuttons) inside the given scope. */
export async function fillDate(scope: Locator | Page, label: string | RegExp, iso: string): Promise<void> {
  const [year, month, day] = iso.split('-');
  const group = scope.getByRole('group', { name: label });
  await group.getByRole('spinbutton', { name: 'اليوم' }).click();
  await group.page().keyboard.type(day);
  await group.getByRole('spinbutton', { name: 'الشهر' }).click();
  await group.page().keyboard.type(month);
  await group.getByRole('spinbutton', { name: 'السنة' }).click();
  await group.page().keyboard.type(year);
}

/** Picks a record in a kit EntitySelect (server typeahead): type, wait for the options, click one. */
export async function pickRecord(page: Page, input: Locator, text: string, option: string | RegExp = text): Promise<void> {
  await input.fill(text);
  await page.getByRole('option', { name: option }).first().click();
}

/** The page does not scroll sideways: nothing wider than the viewport. */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

/** Switches the interface theme through the kit's display menu; the server keeps it in a cookie. */
export async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
  await page.getByRole('button', { name: 'العرض' }).click();
  await page.getByRole('menuitemradio', { name: theme === 'dark' ? 'داكن' : 'فاتح' }).click();
  await page.keyboard.press('Escape');
  await expect(page.locator('html')).toHaveAttribute('data-mode', theme);
}

/** Switches where the navigation goes through the Display menu and waits for the server to keep it in its cookie. */
export async function setNavLayout(page: Page, layout: 'sidebar' | 'topbar'): Promise<void> {
  const saved = page.waitForResponse((response) => response.url().endsWith('/settings/nav-layout') && response.request().method() === 'PUT');
  await page.getByRole('button', { name: 'العرض' }).click();
  await page.getByRole('menuitemradio', { name: layout === 'topbar' ? 'تنقل علوي' : 'قائمة جانبية' }).click();
  expect((await saved).status()).toBeLessThan(400);
  await page.keyboard.press('Escape');
  await expect(page.locator('html')).toHaveAttribute('data-nav-layout', layout);
}

/** Lets the kit's page-enter animation finish before a screenshot or a measurement. */
export const settle = (page: Page): Promise<void> => page.waitForTimeout(700);

/**
 * Fills a calculator's SourcedInput, found by its legend: the value, the source (searched and picked) and the confidence level.
 * The date comes from the source on its own.
 */
export async function fillSourced(page: Page, legend: string | RegExp, value: string, source: string, confidence: string | RegExp): Promise<void> {
  const group = page.getByRole('group', { name: legend });
  await group.getByLabel(/^قيمة/).fill(value);
  await pickRecord(page, group.getByRole('combobox', { name: /^مصدر/ }), source);
  await pickOption(page, group.getByRole('combobox', { name: /^ثقة/ }), confidence);
}

/** The result row of a calculator by its key, e.g. `result(page, 'lye_used')`. */
export const result = (page: Page, key: string): Locator => page.locator(`[data-result="${key}"]`);

/** The sources the demo seeder creates (the SourcedInput finds them by searching). */
export const DEMO_SOURCE = 'Demo supplier data sheet';
export const DEMO_COA = 'Demo batch certificate';
export const CONFIDENCE_TDS = 'نشرة بيانات المورد (TDS)';
export const CONFIDENCE_COA = 'شهادة تحليل الدفعة (COA)';
export const CONFIDENCE_REFERENCE = 'مرجع علمي';

/** The pages that show a calculator or a result: no sideways scroll, in RTL. */
export async function expectRtlAndFits(page: Page): Promise<void> {
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expectNoHorizontalScroll(page);
}

/** Removes every exchange rate, so a spec that adds the first one starts from "no rate" whatever earlier runs left in the database. */
export function resetExchangeRates(): void {
  execSync(`php artisan tinker --execute='App\\Models\\ExchangeRate::query()->delete();'`, { cwd: 'apps/api', stdio: 'pipe' });
}

/** Picks the display currency in the topbar menu (the server keeps it in a cookie). */
export async function setDisplayCurrency(page: Page, name: RegExp | string): Promise<void> {
  await page.getByRole('button', { name: 'العملة المعروضة' }).click();
  await page.getByRole('menuitemradio', { name }).click();
  await page.keyboard.press('Escape');
}
