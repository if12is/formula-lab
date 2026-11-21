import { expect, test, type Page } from '@playwright/test';
import { fillFragranceCase, fillNeutralizationCase, fillSoapCase } from './support/calculators';
import { expectRtlAndFits, result, seedFormulationDemo, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the calculators (S-07), the fragrance calculator (S-14) and the explain panel (S-04), in light
 * and dark, on a desktop and a 390px phone, at deviceScaleFactor 2. Saved to orchestration/evidence/S-07/, S-14/ and S-04/.
 * On a phone the page scrolls inside the shell, so each calculator is shot twice: its inputs, and its results scrolled into view.
 * Every shot also asserts the page is RTL and does not scroll sideways.
 */
test.beforeAll(() => {
  seedFormulationDemo();
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1100 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (folder: string, name: string, target: Page = page): Promise<void> => {
          await settle(target);
          await expectRtlAndFits(target);
          await target.screenshot({ path: `orchestration/evidence/${folder}/${name}-${theme}-${device}.png` });
        };
        const resultsShot = async (folder: string, name: string): Promise<void> => {
          if (device === 'mobile') {
            await page.getByTestId('results-card').scrollIntoViewIfNeeded();
            await shot(folder, `${name}-results`);
          }
        };

        await page.goto('/calculators');
        await expect(page.getByRole('heading', { level: 1, name: 'الحاسبات' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await shot('S-07', 'calculators-index');

        await fillSoapCase(page);
        await page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));
        await shot('S-07', 'calculator-soap');
        await resultsShot('S-07', 'calculator-soap');

        await fillNeutralizationCase(page);
        await page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));
        await shot('S-07', 'calculator-neutralization');
        await resultsShot('S-07', 'calculator-neutralization');

        await fillFragranceCase(page);
        await page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));
        await shot('S-14', 'calculator-fragrance');
        await resultsShot('S-14', 'calculator-fragrance');

        await fillSoapCase(page);
        await result(page, 'lye_used').getByTestId('explain-trigger').click();
        await expect(page.getByTestId('explain-panel')).toBeVisible();
        await shot('S-04', 'explain-panel');
        await page.keyboard.press('Escape');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
