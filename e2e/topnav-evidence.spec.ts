import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, seedFormulationDemo, setNavLayout, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the top navigation (S-TOPNAV): dashboard, formula editor and materials list in light and
 * dark on a desktop, one with a dropdown open, and the 390px drawer, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/S-TOPNAV/. Every shot also asserts the page does not scroll sideways.
 */
const OUT = 'orchestration/evidence/S-TOPNAV';

test.beforeAll(() => {
  seedFormulationDemo();
});

for (const theme of ['light', 'dark'] as const) {
  test.describe(`${theme} desktop`, () => {
    test.use({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 });

    test('screens', async ({ page }) => {
      const shot = async (name: string): Promise<void> => {
        await settle(page);
        await expectNoHorizontalScroll(page);
        await page.screenshot({ path: `${OUT}/${name}-${theme}-desktop.png` });
      };

      await page.goto('/');
      await setNavLayout(page, 'topbar');
      if (theme === 'dark') {
        await setTheme(page, 'dark');
      }
      await expect(page.getByRole('heading', { level: 1, name: 'لوحة التحكم' })).toBeVisible();
      await shot('dashboard-topbar');

      await page.goto('/materials?search=Demo');
      await expect(page.locator('tbody tr').first()).toBeVisible();
      await shot('materials-list-topbar');

      await page.goto('/formulas?search=DEMO-SHAMPOO');
      await page.getByText('DEMO-SHAMPOO').first().click();
      await expect(page).toHaveURL(/\/versions\/3\/edit/);
      await expect(page.getByTestId('line')).toHaveCount(4);
      await shot('formula-editor-topbar');

      await page.goto('/formulas');
      await page.locator('nav.kit-topnav').getByRole('button', { name: 'التركيبات' }).click();
      await expect(page.getByRole('menu').getByRole('menuitem', { name: 'الحاسبات' })).toBeVisible();
      await shot('dropdown-open-topbar');
      await page.keyboard.press('Escape');

      if (theme === 'dark') {
        await setTheme(page, 'light');
      }
      await setNavLayout(page, 'sidebar');
    });
  });
}

test.describe('phone drawer', () => {
  test.use({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });

  test('screens', async ({ page }) => {
    await page.goto('/formulas');
    await setNavLayout(page, 'topbar');
    await page.reload();
    await expectNoHorizontalScroll(page);
    await page.getByRole('button', { name: 'إظهار القائمة' }).click();
    await expect(page.locator('#kit-sidebar')).toHaveAttribute('role', 'dialog');
    await settle(page);
    await expectNoHorizontalScroll(page);
    await page.screenshot({ path: `${OUT}/drawer-topbar-mobile-390.png` });
    await page.keyboard.press('Escape');
    await setNavLayout(page, 'sidebar');
  });
});
