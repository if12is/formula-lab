import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the COA screens (P-05): Arabic RTL, light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2, on the
 * CoaDemoSeeder data (TEST-FIXTURE: made-up certificates read by a scripted stand-in for the model). Read only: nothing is decided here.
 * Saved to orchestration/evidence/P-05/. Every shot also asserts the page is RTL and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/P-05';

test.beforeAll(() => {
  execSync('php artisan db:seed --class=CoaDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

async function open(page: Page, search: string, status: string, lot: string): Promise<void> {
  await page.goto(`/coa?search=${encodeURIComponent(search)}&status=${status}`);
  await page.getByText(lot).first().click();
  await expect(page.getByTestId('coa-review')).toBeVisible();
}

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

        await page.goto('/coa');
        await expect(page.getByTestId('coa-list')).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByText('DEMO-LOT-01').first()).toBeVisible();
        await shot('coa-list');

        await page.getByTestId('coa-upload-open').click();
        await pickRecord(page, page.getByRole('combobox', { name: /^الخامة/ }), 'Demo Glycerin', /Demo Glycerin \(COA\) 99\.7/);
        await shot('coa-upload');
        await page.keyboard.press('Escape');

        // A certificate with a text layer: the checks emptied the made-up pH, the ambiguous viscosity and the unknown test.
        await open(page, 'Demo Glycerin DEMO-LOT-01', 'extracted', 'DEMO-LOT-01');
        await expect(page.getByTestId('coa-unconfirmed')).toBeVisible();
        await shot('coa-review-open');

        // A scan: nothing could be checked by the server, and the original is the image itself.
        await open(page, 'Demo Glycerin DEMO-LOT-02', 'extracted', 'DEMO-LOT-02');
        await expect(page.getByTestId('coa-unverified')).toBeVisible();
        await expect(page.getByTestId('coa-original-image')).toBeVisible();
        await shot('coa-review-scan');

        // Decided: the confirmed one (one value corrected by the person) and the rejected one.
        await open(page, 'decided', 'confirmed', 'DEMO-LOT-01');
        await expect(page.getByTestId('coa-confirmed')).toBeVisible();
        await shot('coa-review-confirmed');
        await open(page, 'decided', 'rejected', 'DEMO-LOT-02');
        await expect(page.getByTestId('coa-rejected')).toBeVisible();
        await shot('coa-review-rejected');
      });
    });
  }
}
