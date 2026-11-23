import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, pickOption, pickRecord, setTheme, settle, stamp } from './support/kit';
import { createDraftFormula } from './support/lab';
import { signInAsOperator, signInAsQualityManager } from './support/sign-in';

/**
 * Phase Q, end to end, in Arabic RTL, light and dark, on a desktop and a 390px phone. Each combination runs the whole journey on an order of its own,
 * and reads the seeded DEMO-PROD / DEMO-PROD-NEUT data (ProductionDemoSeeder) for the states that are slow to build by hand:
 *   Q-01 an order from the approved version: the sheet the engine works out (2.5 kg at 1 g: water 2162, surfactant 300, salt 38; the halves round
 *        to even), approval by a signed-in approver, the message queued for Sanad;
 *   Q-02 the operator's weighing sheet in the order the steps add the materials, with weights only (no price, for the operator role either);
 *   Q-11 the batch: weights and lots, the engine's verdict per line (330 g against 300 g is +10 %, above the TEST-FIXTURE 5 % threshold), a failed
 *        reading, deviations with reason / who / when, the approval, and the closing only once nothing is missing or pending;
 *   Q-05 the lot trace; Q-04 waste against plan, Q-09 actual cost against the computed one, Q-03 alkali with the lots' certificate values (seeded);
 *   Q-10 the process steps: frozen on an approved version, editable on a draft.
 * Hand-computed (TEST-FIXTURE): seeded cost batch 16.911 + 25 + 10 = 51.911 over 2 units = 25.9555 against the approved 18.4573: +7.4982, +40.625 %;
 * neutralisation 1000 g x 180/1000 / 0.9 = 200 g at the reference values, 1000 x 190/1000 / 0.8 = 237.5 g at the lot values.
 * Screenshots go to orchestration/evidence/Q-production/.
 */
const OUT = 'orchestration/evidence/Q-production';

async function openBatchOfOrder(page: Page, orderCode: string): Promise<void> {
  await page.goto(`/production/orders?search=${encodeURIComponent(orderCode)}`);
  await page.getByText(orderCode).first().click();
  await page.getByTestId('open-batch').click();
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2, actionTimeout: 30_000 });

      test('order, weighing sheet, batch, deviations, trace, results, alkali and process steps', async ({ page, context }) => {
        test.setTimeout(420_000);
        const run = `${stamp()}-${theme}-${device}`;
        const lot = `E2E-LOT-${run}`;
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };
        const confirm = async (name: string): Promise<void> => {
          await page.getByRole('alertdialog').getByRole('button', { name, exact: true }).click();
        };

        await signInAsQualityManager(context, run);

        // ---- the list --------------------------------------------------------------------------------------------------
        await page.goto('/production/orders');
        await expect(page.getByRole('heading', { level: 1, name: 'أوامر الإنتاج' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByText('PO-2026-0002').first()).toBeVisible();
        await shot('orders-list');

        // ---- Q-01 an order from the approved version -----------------------------------------------------------------------
        await page.getByTestId('new-order').click();
        await expect(page.getByRole('heading', { level: 1, name: 'أمر إنتاج جديد' })).toBeVisible();
        await settle(page);
        await page.getByTestId('order-submit').click();
        await expect(page.getByText('حقل نسخة التركيبة مطلوب').first()).toBeVisible();
        await shot('order-form-errors');

        await pickRecord(page, page.getByRole('combobox', { name: /^نسخة التركيبة المعتمدة/ }), 'DEMO-PROD', /DEMO-PROD — Demo production shampoo/);
        await page.getByLabel(/^حجم الدفعة/).fill('2.5');
        await pickOption(page, page.getByTestId('order-resolution'), /^1$/);
        await page.getByLabel(/^الوحدات التامة|^عدد الوحدات التامة/).fill('500');
        await page.getByTestId('add-packaging').click();
        await pickRecord(page, page.getByRole('combobox', { name: 'مادة التغليف 1' }), 'Demo Prod Bottle');
        await page.getByLabel('كمية التغليف 1').fill('500');
        await shot('order-form');
        await page.getByTestId('order-submit').click();
        await expect(page).toHaveURL(/\/production\/orders\/[0-9a-f-]{36}$/);

        const sheet = page.getByTestId('sheet-card');
        await expect(sheet.locator('[data-weigh="3"]')).toContainText('2162');
        await expect(sheet.locator('[data-weigh="1"]')).toContainText('300');
        await expect(sheet.locator('[data-weigh="2"]')).toContainText('38');
        await expect(page.getByTestId('sheet-total')).toContainText('2500');
        await expect(page.getByTestId('order-hash')).toHaveText(/^[0-9a-f]{64}$/);
        const orderUrl = page.url();
        await shot('order-draft');

        // ---- approval by a signed-in approver, then the message for Sanad -------------------------------------------------
        await page.getByTestId('approve-order').click();
        await confirm('اعتماد الأمر');
        await expect(page.getByText('تم اعتماد أمر الإنتاج.').first()).toBeVisible();
        await expect(page.getByTestId('send-order')).toBeEnabled();
        await expect(page.getByTestId('approve-order')).toHaveCount(0);
        await page.getByTestId('send-order').click();
        await confirm('إرسال إلى سند');
        await expect(page.getByTestId('outbox-state')).toHaveAttribute('data-status', 'pending');
        await shot('order-sent');

        // ---- Q-02 the weighing sheet: the order the steps add them in, weights only --------------------------------------------
        await page.getByTestId('open-weighing').click();
        await expect(page.getByTestId('weighing-sheet')).toBeVisible();
        await expect(page.getByTestId('weighing-sheet').locator('tbody tr, [data-mobile-table] li').first()).toContainText('Demo Prod Aqua');
        await expect(page.getByTestId('weighing-sheet').locator('[data-weigh]').first()).toContainText('2162');
        await expect(page.getByText('18.4573')).toHaveCount(0);
        await expect(page.getByText('ج.م')).toHaveCount(0);
        await shot('weighing-sheet');

        // ---- Q-11 the batch ------------------------------------------------------------------------------------------------
        await page.goto(orderUrl);
        await page.getByTestId('start-batch').click();
        await page.getByTestId('batch-operator').fill('أحمد');
        await page.getByTestId('batch-submit').click();
        await expect(page).toHaveURL(/\/production\/batches\/[0-9a-f-]{36}$/);
        await expect(page.getByTestId('batch-hash')).toHaveText(/^[0-9a-f]{64}$/);
        await expect(page.getByTestId('complete-batch')).toBeDisabled();
        await shot('batch-started');

        const weigh = async (lineNo: number, actual: string, lotNumber?: string): Promise<void> => {
          await page.getByTestId(`weigh-${lineNo}`).click();
          const dialog = page.getByRole('dialog');
          await dialog.getByLabel(/^الكمية الفعلية/).fill(actual);
          if (lotNumber) {
            await dialog.getByTestId('weigh-lot').fill(lotNumber);
          }
          await dialog.getByTestId('weigh-submit').click();
          await expect(dialog).toHaveCount(0);
          await expect(page.locator(`[data-actual="${lineNo}"]`)).toContainText(actual);
        };
        await weigh(1, '2162', `E2E-W-${run}`);
        await weigh(2, '330', lot);
        await weigh(3, '38', `E2E-S-${run}`);
        await weigh(4, '500');
        await expect(page.locator('[data-verdict="1"]')).toHaveAttribute('data-state', 'none');
        await expect(page.locator('[data-verdict="2"]')).toHaveAttribute('data-state', 'exceeds');
        await expect(page.getByTestId('complete-blockers')).toContainText('سطور فيها انحراف يحتاج تسجيلاً: 2');
        await shot('batch-weighed');

        // A reading outside the sourced limits fails, an unlimited one is "unknown" and never a pass.
        await page.getByTestId('check-3-1').click();
        await page.getByTestId('check-value').fill('6.8');
        await page.getByTestId('check-submit').click();
        await expect(page.locator('[data-reading="3/1"]')).toHaveAttribute('data-status', 'FAIL');
        await page.getByTestId('check-3-2').click();
        await page.getByTestId('check-value').fill('3000');
        await page.getByTestId('check-submit').click();
        await expect(page.locator('[data-reading="3/2"]')).toHaveAttribute('data-status', 'UNKNOWN');
        await expect(page.getByTestId('complete-blockers')).toContainText('نقاط فحص خارج حدودها بلا انحراف مسجل: 3/1');

        // Deviations: the reason is required, then the engine's verdict decides whether an approval is needed.
        await page.getByTestId('deviate-2').click();
        const form = page.getByTestId('deviation-form');
        await form.getByTestId('deviation-submit').click();
        await expect(form).toContainText('حقل السبب مطلوب');
        await shot('deviation-form-errors');
        await form.getByTestId('deviation-reason').fill('انسكب جزء على الميزان فأُضيف الباقي');
        await form.getByTestId('deviation-submit').click();
        await expect(page.getByText('تم تسجيل الانحراف.').first()).toBeVisible();
        await expect(page.locator('[data-approval="pending"]')).toHaveCount(1);

        await page.getByTestId('record-deviation').click();
        await form.getByTestId('deviation-subject').fill('step:3/check:1');
        await pickOption(page, form.getByTestId('deviation-kind'), 'نقطة فحص');
        await form.getByTestId('deviation-reason').fill('ضُبط الأس الهيدروجيني بحمض الستريك');
        await form.getByTestId('deviation-submit').click();
        await expect(page.locator('[data-approval="not_required"]')).toHaveCount(1);
        await expect(page.getByTestId('complete-blockers')).toContainText('انحراف ينتظر الاعتماد');
        await shot('batch-deviations');

        const pending = page.locator('[data-approval="pending"]');
        await pending.getByRole('button', { name: 'اعتماد' }).click();
        await page.getByTestId('decision-note').fill('راجعت العينة المحفوظة');
        await page.getByTestId('decision-submit').click();
        await expect(page.getByText('تم اعتماد الانحراف.').first()).toBeVisible();
        await expect(page.locator('[data-approval="approved"]')).toHaveCount(1);
        await expect(page.getByTestId('complete-blockers')).toHaveCount(0);

        await page.getByTestId('complete-batch').click();
        await confirm('إكمال الدفعة');
        await expect(page.getByText('الدفعة مغلقة')).toBeVisible();
        await expect(page.getByTestId('complete-batch')).toHaveCount(0);
        await shot('batch-completed');

        // ---- Q-05 the lot trace ----------------------------------------------------------------------------------------------
        await page.goto('/production/trace');
        await page.getByTestId('trace-lot').fill(lot);
        await page.getByTestId('trace-search').click();
        await expect(page.getByTestId('trace-result')).toHaveAttribute('data-count', '1');
        await expect(page.getByTestId('trace-result')).toContainText('Demo Prod Surfactant');
        await expect(page.getByTestId('trace-note')).toBeVisible();
        await shot('lot-trace');

        // ---- the operator: weights only, no price, no Sanad ------------------------------------------------------------------
        await signInAsOperator(context, run);
        await page.goto(orderUrl);
        await expect(page.getByTestId('sheet-card')).toBeVisible();
        await expect(page.getByTestId('sanad-card')).toHaveCount(0);
        await expect(page.getByText('18.4573')).toHaveCount(0);
        await expect(page.getByTestId('send-order')).toHaveCount(0);
        await shot('order-operator');
        await page.getByTestId('open-batch').click();
        await expect(page.getByTestId('cost-card')).toHaveCount(0);
        await expect(page.getByTestId('lines-card')).toBeVisible();
        await shot('batch-operator');
        await signInAsQualityManager(context, `${run}-again`);

        // ---- Q-11 / Q-04 / Q-09 on the seeded batches ----------------------------------------------------------------------------
        await openBatchOfOrder(page, 'PO-2026-0002');
        await expect(page.locator('[data-approval="pending"]')).toHaveCount(1);
        await expect(page.locator('[data-reading="3/1"]')).toHaveAttribute('data-status', 'FAIL');
        await expect(page.getByTestId('complete-blockers')).toBeVisible();
        await shot('batch-seeded-in-progress');

        await openBatchOfOrder(page, 'PO-2026-0003');
        await expect(page.getByText('الدفعة مغلقة')).toBeVisible();
        await expect(page.getByTestId('waste-comparison')).toHaveAttribute('data-state', 'compared');
        await expect(page.getByTestId('waste-verdict')).toContainText('ضمن الحد');
        await expect(page.locator('[data-result="actual_unit_cost"]')).toContainText('25.9555');
        await expect(page.getByTestId('cost-comparison')).toHaveAttribute('data-verdict', 'exceeds');
        await expect(page.getByTestId('cost-comparison')).toContainText('40.625');
        await shot('batch-cost-and-waste');

        // ---- Q-03 the alkali with the lots' certificate values -------------------------------------------------------------------
        await openBatchOfOrder(page, 'PO-2026-0004');
        await expect(page.locator('[data-result="alkali_planned"]')).toContainText('200');
        await expect(page.locator('[data-result="alkali_lot"]')).toContainText('237.5');
        await expect(page.locator('[data-result="alkali_difference"]')).toContainText('37.5');
        await expect(page.getByTestId('coa-lots')).toContainText('DEMO-LOT-ACID');
        await shot('alkali-with-lot-values');

        await openBatchOfOrder(page, 'PO-2026-0005');
        await expect(page.getByTestId('alkali-missing')).toContainText('DEMO-LOT-NOCOA'.length > 0 ? 'Demo Prod LABSA' : '');
        await expect(page.getByTestId('alkali-result')).toHaveCount(0);
        await shot('alkali-missing-coa');

        // ---- Q-10 the process steps: frozen on the approved version, editable on a draft ------------------------------------------
        await page.goto('/formulas?search=DEMO-PROD');
        await page.getByText('Demo production shampoo').first().click();
        await page.getByTestId('workflow-process').click();
        await expect(page.getByTestId('process-frozen')).toBeVisible();
        await expect(page.getByTestId('process-steps')).toContainText('5.5');
        await shot('process-frozen');

        const code = `E2E-PROC-${run}`.slice(0, 40);
        await createDraftFormula(page, code, `Process ${run}`);
        await page.getByTestId('workflow-process').click();
        await expect(page.getByTestId('process-form')).toBeVisible();
        await page.getByTestId('add-step').click();
        await pickOption(page, page.getByTestId('step-1-kind'), 'تسخين');
        await page.getByTestId('step-1').getByLabel(/^درجة الحرارة/).first().fill('70');
        await page.getByTestId('add-step').click();
        await pickOption(page, page.getByTestId('step-2-kind'), 'إضافة خامة');
        await pickOption(page, page.getByTestId('step-2-line'), /Demo Water/);
        await page.getByTestId('add-check-2').click();
        await page.getByTestId('check-2-1').getByLabel(/^الحد الأقصى/).fill('7');
        await page.getByTestId('process-save').click();
        await expect(page.getByText(/تحتاج مصدراً ودرجة ثقة|يحتاج مصدراً/).first()).toBeVisible();
        await shot('process-editor-needs-source');

        await page.getByTestId('check-2-1').getByLabel(/^الحد الأقصى/).fill('');
        // The error toast of the first try can sit over the button on a phone.
        await page.getByTestId('process-save').dispatchEvent('click');
        await expect(page.getByText('تم حفظ خطوات التصنيع وتحديث بصمة النسخة.').first()).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('step-1').getByLabel(/^درجة الحرارة/).first()).toHaveValue('70');
        await shot('process-editor-saved');
      });
    });
  }
}
