import { expect, test, type Page } from '@playwright/test';
import { fillSoapCase } from './support/calculators';
import { expectNoHorizontalScroll, result, seedFormulationDemo, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the Taif Kit 1.10.0 upgrade (KIT-1): the dashboard's "—" stat cards, the display currency inside
 * the Display menu (ThemeMenu #extra), a list with the kit's count and Clear all, the explain panel's trace tree on
 * TreeView #item, and a unit Select inside an Input suffix. Light and dark at 1280 and at 390, RTL, deviceScaleFactor 2.
 * Saved to orchestration/evidence/KIT-1.10/. Every shot also asserts the page does not scroll sideways.
 */
const OUT = 'orchestration/evidence/KIT-1.10';

test.beforeAll(() => {
  seedFormulationDemo();
});

async function shoot(page: Page, name: string, fullPage = false): Promise<void> {
  await settle(page);
  await expectNoHorizontalScroll(page);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage });
}

async function openDisplayMenu(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'العرض' }).click();
  const currency = page.getByRole('menuitemradio', { name: /EGP|جنيه/ }).first();
  await currency.scrollIntoViewIfNeeded();
  await expect(currency).toBeVisible();
}

const SIZES = [
  { id: 'desktop', width: 1280, height: 800 },
  { id: 'mobile', width: 390, height: 844 },
] as const;

for (const theme of ['light', 'dark'] as const) {
  for (const size of SIZES) {
    test.describe(`${theme} ${size.id}`, () => {
      test.use({ viewport: { width: size.width, height: size.height }, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const tag = `${theme}-${size.id}`;

        await page.goto('/');
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByRole('heading', { level: 1, name: 'لوحة التحكم' })).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await shoot(page, `dashboard-stat-dash-${tag}`);

        await openDisplayMenu(page);
        await shoot(page, `display-menu-currency-${tag}`);
        await page.keyboard.press('Escape');

        await page.goto('/materials?search=Demo&status=active&sort=trade_name&dir=desc');
        await expect(page.getByRole('searchbox')).toHaveValue('Demo');
        await expect(page.locator('[data-filter-count]')).toHaveText('1');
        await shoot(page, `materials-filters-count-clear-${tag}`);

        // QuantityInput: the unit Select sits in the Input's suffix at the kit's 1.10 addon width (no width workaround).
        await page.goto('/calculators/fragrance');
        await page.getByRole('button', { name: 'تخفيف الكحول' }).click();
        await expect(page.getByRole('combobox', { name: /وحدة حجم الدفعة/ })).toBeVisible();
        expect((await page.getByLabel('حجم الدفعة', { exact: true }).boundingBox())?.width).toBeGreaterThan(60);
        await shoot(page, `unit-select-in-suffix-${tag}`, true);

        // The explain panel: the trace tree is the kit's TreeView with the #item slot.
        await fillSoapCase(page);
        await result(page, 'lye_used').getByTestId('explain-trigger').click();
        await expect(page.getByTestId('explain-panel').getByTestId('trace-tree').locator('[data-trace-leaf]').first()).toBeVisible();
        await shoot(page, `trace-tree-${tag}`);
      });
    });
  }
}
