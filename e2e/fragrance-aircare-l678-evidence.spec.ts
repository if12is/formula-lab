import { expect, test, type Page } from '@playwright/test';
import { fillAerosolCase, fillCandleCase, fillGelCase } from './support/aircare';
import { expectRtlAndFits, result, setTheme, settle } from './support/kit';

/**
 * Review screenshots of L-06 to L-08 (aerosol, gel blend, candle), in light and dark, on a desktop and a 390px phone, at
 * deviceScaleFactor 2, saved to orchestration/evidence/L-aircare/. On a phone the calculator is shot twice: its inputs and its
 * results scrolled into view. Every shot asserts RTL and no sideways scroll.
 */
test.setTimeout(300_000);

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1100 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, target: Page = page): Promise<void> => {
          await settle(target);
          await expectRtlAndFits(target);
          await target.screenshot({ path: `orchestration/evidence/L-aircare/${name}-${theme}-${device}.png` });
        };
        const top = (): Promise<void> => page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));
        const screen = async (name: string, hasResults = true): Promise<void> => {
          await top();
          await shot(name);
          if (hasResults && device === 'mobile') {
            await page.getByTestId('results-card').scrollIntoViewIfNeeded();
            await shot(`${name}-results`);
          }
        };

        await fillAerosolCase(page);
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(result(page, 'propellant_mass')).toBeVisible();
        await screen('L06-aerosol');

        await fillGelCase(page);
        await screen('L08-gel-blend');

        await fillCandleCase(page);
        await screen('L07-candle');

        await page.goto('/families/aerosol_air_freshener');
        await screen('L06-aerosol-family', false);

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
