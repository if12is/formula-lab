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

/** Lets the kit's page-enter animation finish before a screenshot or a measurement. */
export const settle = (page: Page): Promise<void> => page.waitForTimeout(700);
