import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, setTheme, settle } from './support/kit';
import { seedLabDemo } from './support/lab';

/**
 * Review screenshots of the lab loop (O-01, O-02, O-06): the trials list, the trial entry form, an open and a completed trial,
 * the approval workflow on the formula editor (blocked in lab testing, and approved with its signature) and the protocol
 * templates, in Arabic RTL, light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/O-lab-loop/. Every shot also asserts the page is RTL and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/O-lab-loop';

test.beforeAll(() => {
  seedLabDemo();
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, panel = false): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          const path = `${OUT}/${name}-${theme}-${device}.png`;
          if (panel) {
            await page.getByTestId('workflow-panel').screenshot({ path });
          } else {
            await page.screenshot({ path, fullPage: true });
          }
        };

        await page.goto('/lab/trials');
        await expect(page.getByRole('heading', { level: 1, name: 'تجارب المعمل' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('trials-list');

        // The entry form, reached from the formula editor's link so the version is chosen.
        await page.goto('/formulas?search=DEMO-LAB-BLOCKED');
        await page.getByText('DEMO-LAB-BLOCKED').first().click();
        await expect(page.getByTestId('workflow-panel')).toHaveAttribute('data-status', 'lab_testing');
        await expect(page.getByTestId('workflow-blocked')).toBeVisible();
        await shot('workflow-lab-testing-blocked', true);
        await page.getByTestId('workflow-new-trial').click();
        await expect(page.getByRole('combobox', { name: /^نسخة التركيبة/ })).toHaveValue(/DEMO-LAB-BLOCKED/);
        await page.getByLabel(/^حجم الدفعة/).fill('500');
        await page.getByLabel(/^القائم بالتجربة/).fill('أحمد');
        await shot('trial-form');

        await page.goto('/lab/trials?search=DEMO-SHAMPOO/T2');
        await page.getByText('DEMO-SHAMPOO/T2').first().click();
        await expect(page.getByTestId('measurement-form')).toBeVisible();
        // A blank value is refused: the error shows and the property stays "not measured".
        await page.getByTestId('measurement-form').getByLabel(/^الجهاز المستخدم/).fill('Demo meter');
        await page.getByTestId('measurement-form').getByLabel(/^الطريقة/).fill('Demo method');
        await page.getByTestId('measurement-submit').click();
        await expect(page.getByTestId('measurement-form')).toContainText('حقل القيمة مطلوب');
        await shot('trial-open-with-form');

        await page.goto('/lab/trials?search=DEMO-SHAMPOO/T1');
        await page.getByText('DEMO-SHAMPOO/T1').first().click();
        await expect(page.getByTestId('earlier-readings')).toBeVisible();
        await shot('trial-completed');

        await page.goto('/formulas?search=DEMO-LAB-APPROVED');
        await page.getByText('DEMO-LAB-APPROVED').first().click();
        await expect(page.getByTestId('workflow-panel')).toHaveAttribute('data-status', 'approved');
        await expect(page.getByTestId('signature-hash').first()).toHaveText(/^[0-9a-f]{64}$/);
        await shot('workflow-approved-signed', true);

        await page.goto('/lab/protocols');
        await expect(page.getByRole('heading', { level: 1, name: 'بروتوكولات الاختبار' })).toBeVisible();
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('protocols-list');
        await page.getByText('الكثافة بالبيكنومتر').first().click();
        await expect(page.getByTestId('protocol-steps')).toBeVisible();
        await shot('protocol-detail');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
