import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, seedFormulationDemo, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the formulation screens (S-03): the materials library, a material, the formula editor and
 * the version diff, in light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/S-03/. Every shot also asserts the page does not scroll sideways.
 */
const OUT = 'orchestration/evidence/S-03';

test.beforeAll(() => {
  seedFormulationDemo();
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        await page.goto('/materials?search=Demo');
        await expect(page.getByRole('heading', { level: 1, name: 'الخامات' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('materials-list');

        await page.getByText('Demo SLES').first().click();
        await expect(page.locator('[data-property="density"]')).toBeVisible();
        await shot('material-detail');

        await page.goto('/formulas?search=DEMO-SHAMPOO');
        await page.getByText('DEMO-SHAMPOO').first().click();
        await expect(page).toHaveURL(/\/versions\/3\/edit/);
        await expect(page.getByTestId('line')).toHaveCount(4);
        await shot('formula-editor');

        await page.getByRole('link', { name: 'مقارنة النسخ' }).click();
        await expect(page.getByTestId('diff')).toBeVisible();
        await page.goto(`${new URL(page.url()).pathname}?from=1&to=3`);
        await expect(page.locator('[data-diff="added"]')).toBeVisible();
        await shot('version-diff');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
