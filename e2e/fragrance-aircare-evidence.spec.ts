import { expect, test, type Page } from '@playwright/test';
import { fillPerfumeCostCase, fillRoomSprayCase, seedFragranceDemo } from './support/fragrance';
import { expectRtlAndFits, result, setTheme, settle } from './support/kit';

/**
 * Review screenshots of phase L (the fragrance oil tab, the perfume cost, the room spray), in light and dark, on a desktop and a
 * 390px phone, at deviceScaleFactor 2. Saved to orchestration/evidence/L-fragrance/. On a phone the page scrolls inside the
 * shell, so a calculator is shot twice: its inputs, and its results scrolled into view. Every shot asserts RTL and no sideways scroll.
 */
test.beforeAll(() => {
  seedFragranceDemo();
});

// Each screen fills a calculator case of about twenty sourced fields, three times: allow for it.
test.setTimeout(300_000);

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1100 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, target: Page = page): Promise<void> => {
          await settle(target);
          await expectRtlAndFits(target);
          await target.screenshot({ path: `orchestration/evidence/L-fragrance/${name}-${theme}-${device}.png` });
        };
        const resultsShot = async (name: string): Promise<void> => {
          if (device === 'mobile') {
            await page.getByTestId('results-card').scrollIntoViewIfNeeded();
            await shot(`${name}-results`);
          }
        };
        const top = (): Promise<void> => page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));

        await page.goto('/materials?search=' + encodeURIComponent('Demo Fragrance Oil'));
        await page.getByText('Demo Fragrance Oil').first().click();
        await page.getByRole('tab', { name: /زيت العطر/ }).click();
        await expect(page.getByTestId('fragrance-panel')).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await shot('L01-fragrance-oil-tab');

        await fillPerfumeCostCase(page, 'lab');
        await expect(result(page, 'cost_direct_total')).toBeVisible();
        await top();
        await shot('L10-perfume-cost');
        await resultsShot('L10-perfume-cost');

        await fillPerfumeCostCase(page, 'typed');
        await page.getByRole('group', { name: 'فاقد الفلترة' }).getByLabel(/^قيمة/).fill('');
        await expect(page.getByTestId('missing')).toBeVisible();
        await resultsShot('L04-loss-missing');
        if (device === 'desktop') {
          await top();
          await shot('L04-loss-missing');
        }

        await fillRoomSprayCase(page);
        await top();
        await shot('L05-room-spray');
        await resultsShot('L05-room-spray');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
