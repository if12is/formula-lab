import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_TDS, DEMO_SOURCE, expectNoHorizontalScroll, fillSourced, pickOption, pickRecord, result, setTheme, settle } from './support/kit';
import { seedLabDemo } from './support/lab';

/**
 * Review screenshots of the second half of the lab loop (O-03, O-04, O-05, O-07): the salt-curve entry and the curve read by
 * the engine (and its "no curve" answer), the measured density used in litre/kilo, the expected-vs-measured comparison and the
 * "tested on" tag with its records, in Arabic RTL, light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/O-lab-loop-2/. Every shot also asserts the page is RTL and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/O-lab-loop-2';

test.beforeAll(() => {
  seedLabDemo();
});

async function openTrial(page: Page, code: string): Promise<void> {
  await page.goto(`/lab/trials?search=${encodeURIComponent(code)}`);
  await page.getByText(code, { exact: true }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: code })).toBeVisible();
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, testId?: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          const path = `${OUT}/${name}-${theme}-${device}.png`;
          if (testId) {
            await page.getByTestId(testId).screenshot({ path });
          } else {
            await page.screenshot({ path, fullPage: true });
          }
        };

        // O-03: the entry (a viscosity reading with its salt %), then the formula's curve read by the engine.
        await openTrial(page, 'DEMO-LAB-CURVE-SHOTS/T2');
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        const form = page.getByTestId('measurement-form');
        await pickOption(page, form.getByTestId('measurement-property'), 'اللزوجة');
        await form.getByLabel(/^القيمة/).fill('3150');
        await form.getByLabel(/^الملح المضاف/).fill('2');
        await form.getByLabel(/^الطريقة/).fill('Demo bench method');
        await form.getByLabel(/^الجهاز المستخدم/).fill('Demo Brookfield 2');
        await shot('salt-entry', 'measurement-form');

        await page.getByTestId('salt-curve-link').click();
        await expect(page.getByTestId('salt-curve')).toHaveAttribute('data-points', '2'); // the seeded 0 % and 1 % points: this formula is its own, lab-loop-2.spec adds its reading to DEMO-LAB-CURVE
        await page.getByTestId('salt-query').getByRole('textbox').fill('0.5');
        await expect(result(page, 'viscosity')).toContainText('635.0');
        await expect(page.getByTestId('salt-chart').locator('svg').first()).toBeVisible();
        await page.waitForTimeout(1500);
        await shot('salt-curve');

        await openTrial(page, 'DEMO-SHAMPOO/T1');
        await page.getByTestId('salt-curve-link').click();
        await page.getByTestId('salt-query').getByRole('textbox').fill('1');
        await expect(page.getByTestId('salt-result').getByTestId('missing')).toBeVisible();
        await shot('salt-curve-missing');

        // O-04: the density measured for the version, used in litre/kilo ahead of a typed estimate.
        await page.goto('/calculators/litre-kilo');
        await page.getByLabel('الوزن', { exact: true }).fill('2.06');
        await pickRecord(page, page.getByRole('combobox', { name: /^نسخة التركيبة/ }), 'DEMO-LAB-APPROVED', /DEMO-LAB-APPROVED/);
        await fillSourced(page, 'الكثافة المقاسة', '2', DEMO_SOURCE, CONFIDENCE_TDS);
        await expect(result(page, 'volume')).toContainText('2.000');
        await shot('density-conversion');
        await result(page, 'volume').getByTestId('explain-trigger').click();
        await expect(page.getByTestId('explain-panel')).toBeVisible();
        await shot('density-explain');
        await page.keyboard.press('Escape');

        // O-05: computed against measured, then a trial that cannot be compared (missing sides, listed by the engine).
        await openTrial(page, 'DEMO-LAB-CURVE/T2');
        await expect(page.getByTestId('comparison')).toHaveAttribute('data-state', 'ready');
        await shot('comparison', 'comparison');
        await openTrial(page, 'DEMO-SHAMPOO/T2');
        await expect(page.getByTestId('comparison').getByTestId('missing')).toBeVisible();
        await shot('comparison-missing', 'comparison');

        // O-07: the records of a tested version, and the tag on the HLB calculator.
        await openTrial(page, 'DEMO-LAB-TESTED/T1');
        await expect(page.getByTestId('tested-values').locator('[data-tested="required_hlb:11"]')).toBeVisible();
        await shot('tested-values', 'tested-values');
        await page.goto('/calculators/hlb');
        await pickRecord(page, page.getByRole('combobox', { name: 'المكوّن الزيتي 1' }), 'Demo Lab Surf', /Demo Lab Surfactant/);
        await page.getByLabel('كتلة المكوّن 1', { exact: true }).fill('50');
        await expect(page.getByTestId('tested-on')).toBeVisible();
        await shot('tested-on-tag');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
