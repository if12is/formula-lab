import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, settle, stamp } from './support/kit';
import { createDraftFormula, recordMeasurement, seedLabDemo, signMove } from './support/lab';

/**
 * The lab loop (phase O): trials with measurements, the approval workflow on the formula editor, the protocol templates.
 * Hand-checked rules: no approval without a completed trial holding pH, density and viscosity (config/lab.php); a
 * measurement nobody took is shown as "not measured", never 0 (a blank value is refused, a typed 0 is a real value);
 * rejection needs a reason; an approved version is final but can still be tested.
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedLabDemo();
});

const suffix = stamp();
const code = `LAB-${suffix}`.toUpperCase();

test('trials list: seeded trials, search, filters and sort live in the address, and a filter with no match says so', async ({ page }) => {
  await page.goto('/lab');
  await expect(page).toHaveURL(/\/lab\/trials/);
  await expect(page.getByRole('heading', { level: 1, name: 'تجارب المعمل' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'بروتوكولات الاختبار' })).toBeVisible();
  await settle(page);

  const rows = page.locator('tbody tr');
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T1' })).toHaveCount(1);
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T2' })).toHaveCount(1);

  await page.getByRole('searchbox').fill('Demo Technician');
  await expect(page).toHaveURL(/search=Demo\+Technician|search=Demo%20Technician/);
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T2' })).toHaveCount(1);
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T1' })).toHaveCount(0);

  await page.goto('/lab/trials?status=completed&search=DEMO-SHAMPOO');
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T1' })).toHaveCount(1);
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T2' })).toHaveCount(0);

  await page.goto('/lab/trials?search=no-such-trial');
  await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر')).toBeVisible();
  await page.getByRole('button', { name: 'مسح كل الفلاتر' }).first().click();
  await expect(page).not.toHaveURL(/search=/);
  await expect(rows.filter({ hasText: 'DEMO-SHAMPOO/T1' })).toHaveCount(1);
});

test('a completed demo trial of an approved version: latest reading counts, the earlier one stays, unmeasured is never zero', async ({ page }) => {
  await page.goto('/lab/trials?search=DEMO-SHAMPOO/T1');
  await page.getByText('DEMO-SHAMPOO/T1').first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'DEMO-SHAMPOO/T1' })).toBeVisible();
  await settle(page);

  await expect(page.locator('[data-value="ph"]')).toContainText('5.9');
  await expect(page.locator('[data-value="density"]')).toContainText('1.04');
  await expect(page.locator('[data-value="viscosity"]')).toContainText('3200');
  // Cloud point and the 4-week stability were never measured: a clear "not measured", no figure at all.
  for (const property of ['cloud_point', 'stability_4w']) {
    const cell = page.locator(`[data-value="${property}"]`);
    await expect(cell).toContainText('لم يُقَس');
    await expect(cell).not.toContainText('0');
  }
  await expect(page.getByTestId('earlier-readings')).toContainText('5.8');
  // No acceptance limit exists, and it says so instead of passing the value.
  await expect(page.locator('[data-property-row="ph"]').first()).toBeVisible();
  await expect(page.getByText('لا يوجد حد معرّف').first()).toBeVisible();
  await expect(page.getByTestId('coverage')).toHaveAttribute('data-covered', 'true');
  // Completed: closed, no form.
  await expect(page.getByTestId('measurement-form')).toHaveCount(0);
  await expect(page.getByText('هذه التجربة مكتملة')).toBeVisible();
});

test('approval workflow end to end: draft, lab testing, blocked until a completed trial holds every required measurement, then signed approval', async ({ page }) => {
  await createDraftFormula(page, code, `Lab spec ${suffix}`);
  const panel = page.getByTestId('workflow-panel');
  const editorUrl = page.url();

  // draft -> lab testing, signed.
  await expect(panel.getByTestId('workflow-approve')).toHaveCount(0);
  await signMove(page, 'إرسال إلى اختبار المعمل', 'Ready for the bench');
  await expect(page.getByText('تم إرسال النسخة إلى اختبار المعمل.')).toBeVisible();
  await expect(panel).toHaveAttribute('data-status', 'lab_testing');
  await expect(panel.getByTestId('workflow-signatures')).toContainText('Ready for the bench');

  // No trial yet: approval is blocked and says why.
  await expect(panel.getByTestId('workflow-approve')).toBeDisabled();
  await expect(panel.getByTestId('workflow-blocked')).toBeVisible();
  await expect(panel.getByTestId('coverage')).toContainText('لا توجد تجربة مكتملة بعد');

  // Open a trial from the editor: the version is already chosen.
  await panel.getByTestId('workflow-new-trial').click();
  await expect(page).toHaveURL(/\/lab\/trials\/create\?formula_version=/);
  await expect(page.getByRole('combobox', { name: /^نسخة التركيبة/ })).toHaveValue(new RegExp(`${code} — Lab spec ${suffix} · v1`));

  // Validation: a batch of 0 is refused.
  await page.getByLabel(/^حجم الدفعة/).fill('0');
  await page.getByLabel(/^القائم بالتجربة/).fill('Mona');
  await page.getByTestId('trial-submit').click();
  await expect(page).toHaveURL(/\/lab\/trials\/create/);
  await expect(page.getByText(/حجم الدفعة/).last()).toBeVisible();

  await page.getByLabel(/^حجم الدفعة/).fill('500');
  await page.getByTestId('trial-submit').click();
  await expect(page).toHaveURL(/\/lab\/trials\/[0-9a-f-]{36}$/);
  await expect(page.getByText('تم فتح التجربة. أضف قياساتها.')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: `${code}/T1` })).toBeVisible();
  await expect(page.getByTestId('complete-trial')).toBeDisabled();
  const trialUrl = page.url();

  // A blank value is refused, nothing is stored (no zero).
  await page.getByTestId('measurement-form').getByLabel(/^الجهاز المستخدم/).fill('Meter 1');
  await page.getByTestId('measurement-form').getByLabel(/^الطريقة/).fill('Bench method');
  await page.getByTestId('measurement-submit').click();
  await expect(page.getByTestId('measurement-form')).toContainText('حقل القيمة مطلوب');
  await expect(page.locator('[data-value="ph"]')).toContainText('لم يُقَس');

  // pH only, then complete: the version is still not approvable.
  await recordMeasurement(page, { property: 'الأس الهيدروجيني (pH)', value: '7.2', method: 'Bench method', instrument: 'Meter 1' });
  await expect(page.getByText('تم تسجيل القياس.')).toBeVisible();
  await expect(page.locator('[data-value="ph"]')).toContainText('7.2');
  await expect(page.locator('[data-value="density"]')).toContainText('لم يُقَس');
  await page.getByTestId('complete-trial').click();
  await page.getByRole('alertdialog').or(page.getByRole('dialog')).getByRole('button', { name: 'إكمال التجربة' }).click();
  await expect(page.getByText('تم إكمال التجربة وإغلاقها.')).toBeVisible();
  await expect(page.getByTestId('measurement-form')).toHaveCount(0);

  await page.goto(editorUrl);
  await expect(panel.getByTestId('workflow-approve')).toBeDisabled();
  await expect(panel.locator('[data-coverage-property="ph"]')).toHaveAttribute('data-state', 'measured');
  await expect(panel.locator('[data-coverage-property="density"]')).toHaveAttribute('data-state', 'missing');
  await expect(panel.locator('[data-coverage-property="viscosity"]')).toHaveAttribute('data-state', 'missing');

  // A second trial that holds all three required measurements (a real 0 reading is a value, stored as 0).
  await panel.getByTestId('workflow-new-trial').click();
  await page.getByLabel(/^حجم الدفعة/).fill('250');
  await page.getByLabel(/^القائم بالتجربة/).fill('Hana');
  await page.getByTestId('trial-submit').click();
  await expect(page.getByRole('heading', { level: 1, name: `${code}/T2` })).toBeVisible();
  await recordMeasurement(page, { property: 'الأس الهيدروجيني (pH)', value: '7.0', method: 'Bench method', instrument: 'Meter 1' });
  await expect(page.getByText('تم تسجيل القياس.')).toBeVisible();
  await recordMeasurement(page, { property: 'الكثافة', value: '1.02', method: 'Pycnometer', instrument: 'Pycnometer 1' });
  await expect(page.locator('[data-value="density"]')).toContainText('1.02');
  await recordMeasurement(page, { property: 'اللزوجة', value: '2800', method: 'Brookfield', instrument: 'Brookfield 2' });
  await expect(page.locator('[data-value="viscosity"]')).toContainText('2800');
  await recordMeasurement(page, { property: 'نقطة التعكر', value: '0', method: 'Visual', instrument: 'Thermometer' });
  await expect(page.locator('[data-value="cloud_point"]')).toContainText('0');
  await expect(page.locator('[data-value="cloud_point"]')).not.toContainText('لم يُقَس');
  await expect(page.getByTestId('coverage')).toHaveAttribute('data-covered', 'false');
  await page.getByTestId('complete-trial').click();
  await page.getByRole('alertdialog').or(page.getByRole('dialog')).getByRole('button', { name: 'إكمال التجربة' }).click();
  await expect(page.getByText('تم إكمال التجربة وإغلاقها.')).toBeVisible();
  await expect(page.getByTestId('coverage')).toHaveAttribute('data-covered', 'true');
  expect(trialUrl).not.toBe(page.url());

  // Back on the editor, approval is open: signed with a comment.
  await page.goto(editorUrl);
  await expect(panel.getByTestId('workflow-approve')).toBeEnabled();
  await signMove(page, 'اعتماد', 'Passed the bench');
  await expect(page.getByText('تم اعتماد النسخة وتوقيعها.')).toBeVisible();
  await expect(panel).toHaveAttribute('data-status', 'approved');
  await expect(panel.getByText('معتمدة وموقَّعة')).toBeVisible();
  const signatures = panel.getByTestId('workflow-signatures');
  await expect(signatures).toContainText('Passed the bench');
  await expect(signatures).toContainText('معتمدة');
  await expect(signatures.getByTestId('signature-hash').first()).toHaveText(/^[0-9a-f]{64}$/);

  // The approved version is read-only, no workflow buttons, but a new trial of it is still allowed.
  await expect(panel.getByTestId('workflow-approve')).toHaveCount(0);
  await expect(panel.getByTestId('workflow-reject')).toHaveCount(0);
  await expect(page.getByText('نسخة معتمدة')).toBeVisible();
  await expect(panel.getByTestId('workflow-new-trial')).toBeVisible();
});

test('rejection needs a reason, then the version is closed', async ({ page }) => {
  await createDraftFormula(page, `${code}-R`, `Lab reject ${suffix}`);
  const panel = page.getByTestId('workflow-panel');
  await signMove(page, 'إرسال إلى اختبار المعمل');
  await expect(panel).toHaveAttribute('data-status', 'lab_testing');

  await panel.getByTestId('workflow-reject').click();
  const modal = page.getByRole('dialog');
  await modal.getByTestId('workflow-confirm').click();
  await expect(modal.getByText(/حقل .* مطلوب/)).toBeVisible();
  await expect(panel).toHaveAttribute('data-status', 'lab_testing');

  await modal.getByTestId('workflow-text').fill('Separated after a week');
  await modal.getByTestId('workflow-confirm').click();
  await expect(page.getByText('تم رفض النسخة.')).toBeVisible();
  await expect(panel).toHaveAttribute('data-status', 'rejected');
  await expect(panel.getByTestId('workflow-signatures')).toContainText('Separated after a week');
  await expect(panel.getByTestId('workflow-approve')).toHaveCount(0);
  await expect(panel.getByTestId('workflow-send')).toHaveCount(0);
});

test('test protocol templates: list, filter and a protocol with its steps and no limit', async ({ page }) => {
  await page.goto('/lab/protocols');
  await expect(page.getByRole('heading', { level: 1, name: 'بروتوكولات الاختبار' })).toBeVisible();
  await settle(page);
  const rows = page.locator('tbody tr');
  await expect(rows).toHaveCount(6);
  await expect(rows.filter({ hasText: 'الكثافة بالبيكنومتر' })).toHaveCount(1);

  await page.goto('/lab/protocols?search=brook');
  await expect(rows).toHaveCount(1);
  await rows.first().click();
  await expect(page).toHaveURL(/\/lab\/protocols\/[0-9a-f-]{36}/);
  await expect(page.getByRole('heading', { level: 1, name: 'اللزوجة بمقياس بروكفيلد' })).toBeVisible();
  await expect(page.getByTestId('protocol-steps').locator('li')).toHaveCount(4);
  await expect(page.getByText('مقياس لزوجة بروكفيلد').first()).toBeVisible();
  await expect(page.getByText('لا يوجد حد معرّف')).toBeVisible();
  await expectNoHorizontalScroll(page);
});
