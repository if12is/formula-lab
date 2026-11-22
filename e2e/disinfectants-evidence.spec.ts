import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, setTheme, settle } from './support/kit';

/**
 * Review screenshots of K-04 / K-05 / N-12: the families list filtered to disinfectants, a fabric softener family page, an alcohol
 * disinfectant family page with the EDA path notice, and the compliance panel of a disinfectant formula; in Arabic RTL, light and
 * dark, on a desktop and a 390px phone, at deviceScaleFactor 2. Saved to orchestration/evidence/K04-K05-N12/.
 */
const OUT = 'orchestration/evidence/K04-K05-N12';

test.beforeAll(() => {
  execSync('php artisan db:seed --class=DisinfectantDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        await page.goto('/families?group=disinfectants');
        await expect(page.getByRole('heading', { level: 1, name: 'عائلات المنتجات' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('families-disinfectants');

        await page.goto('/families/fabric_softener');
        await expect(page.getByRole('heading', { level: 1, name: 'منعم أقمشة' })).toBeVisible();
        await shot('family-fabric-softener');

        await page.goto('/families/alcohol_disinfectant');
        await expect(page.getByTestId('disinfection-notice')).toHaveAttribute('data-status', 'applies');
        await shot('family-alcohol-disinfectant');

        await page.goto('/formulas?search=DEMO-DISINFECTANT');
        await page.getByText('DEMO-DISINFECTANT', { exact: true }).first().click();
        await expect(page.getByTestId('disinfection-notice')).toHaveAttribute('data-status', 'applies');
        await page.getByTestId('compliance-card').scrollIntoViewIfNeeded();
        await shot('formula-compliance-panel');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
