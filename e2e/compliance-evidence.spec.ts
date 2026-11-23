import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, seedComplianceDemo, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the compliance screens (S-06): the rule sets list, a rule set, the formula editor's Compliance panel
 * (PASS, FAIL, UNKNOWN) and the label page, in light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/S-06/. Every shot also asserts the page is right-to-left and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/S-06';

test.beforeAll(() => {
  seedComplianceDemo();
});

async function openFormula(page: Page, code: string): Promise<void> {
  await page.goto(`/formulas?search=${code}`);
  await page.getByText(code, { exact: true }).first().click();
  await expect(page).toHaveURL(/\/versions\/1\/edit/);
  await expect(page.getByTestId('compliance-card')).toBeVisible();
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        test.setTimeout(120_000);
        const shot = async (name: string, target?: ReturnType<Page['getByTestId']>): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          const path = `${OUT}/${name}-${theme}-${device}.png`;

          if (target) {
            await target.screenshot({ path });
          } else {
            await page.screenshot({ path, fullPage: true });
          }
        };

        await page.goto('/compliance');
        await expect(page.getByRole('heading', { level: 1, name: 'المطابقة' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByText('demo-test-substance').first()).toBeVisible();
        await shot('rule-sets-list');

        await page.getByText('demo-test-substance').first().click();
        await expect(page.locator('[data-rule="demo-test-0001-max"]')).toBeVisible();
        await shot('rule-set-detail');

        await page.goto('/compliance?search=methanol');
        await page.getByText('methanol-fragrance').first().click();
        await expect(page.getByTestId('pending-source-alert')).toBeVisible();
        await shot('rule-set-detail-draft');

        await openFormula(page, 'CMPL-PASS');
        await expect(page.getByTestId('compliance-overall')).toContainText('نجح');
        await shot('editor-compliance-pass', page.getByTestId('compliance-card'));

        await openFormula(page, 'CMPL-FAIL');
        await expect(page.getByTestId('compliance-overall')).toContainText('فشل');
        await shot('editor-compliance-fail', page.getByTestId('compliance-card'));

        await openFormula(page, 'CMPL-UNKNOWN');
        await expect(page.getByTestId('compliance-overall')).toContainText('غير معروف');
        await shot('editor-compliance-unknown', page.getByTestId('compliance-card'));

        await openFormula(page, 'CMPL-FRAGRANCE');
        await expect(page.getByTestId('ifra-overall')).toContainText('نجح');
        await shot('editor-compliance-methanol-ifra', page.getByTestId('compliance-card'));

        await openFormula(page, 'CMPL-PASS');
        await page.getByTestId('open-label').click();
        await expect(page.getByTestId('inci-list')).toBeVisible();
        await shot('label');

        await openFormula(page, 'CMPL-UNKNOWN');
        await page.getByTestId('open-label').click();
        await expect(page.getByTestId('inci-gaps')).toBeVisible();
        await shot('label-incomplete');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
