import { expect, test } from '@playwright/test';
import { fillFragranceCase } from './support/calculators';
import { fillRoomSprayCase, fillPerfumeCostCase, seedFragranceDemo } from './support/fragrance';
import { CONFIDENCE_COA, CONFIDENCE_TDS, DEMO_COA, DEMO_SOURCE, fillDate, pickOption, pickRecord, result } from './support/kit';

/**
 * Phase L: the fragrance oil as a material (L-01), process losses from trials (L-04), the water-based room spray (L-05) and
 * the full perfume cost (L-10). Every expected figure is the hand computation of the engine's own tests.
 */
test.beforeAll(() => {
  seedFragranceDemo();
});

// A calculator case has about twenty sourced fields to fill (each searches and picks a source): allow for a loaded machine.
test.setTimeout(120_000);

test('L-10: the full perfume cost by hand, each component with its own line and the direct total', async ({ page }) => {
  await fillPerfumeCostCase(page);

  // 0.09 kg per bottle: oil 90, alcohol 3.555, water 0.0711, fixative 0.9 (no loss), bottle 12, sprayer 3, box 2.
  await expect(result(page, 'cost_oil')).toContainText('90.0000');
  await expect(result(page, 'cost_alcohol')).toContainText('3.5550');
  await expect(result(page, 'cost_water')).toContainText('0.0711');
  await expect(result(page, 'cost_fixative')).toContainText('0.9000');
  await expect(result(page, 'cost_loss')).toContainText('0.0000');
  await expect(result(page, 'cost_bottle')).toContainText('12.0000');
  await expect(result(page, 'cost_sprayer')).toContainText('3.0000');
  await expect(result(page, 'cost_box')).toContainText('2.0000');
  await expect(result(page, 'cost_direct_total')).toContainText('111.5261');
  await expect(page.getByTestId('warnings')).toContainText('التكلفة المباشرة');

  // The trace of the total reaches the sourced prices.
  await result(page, 'cost_direct_total').getByTestId('explain-trigger').click();
  await expect(page.getByTestId('explain-panel')).toBeVisible();
  await page.keyboard.press('Escape');

  // A 20 % ageing loss makes the product 94.5261 / 0.8 = 118.157625: 111.5261 + 23.631525.
  await page.getByRole('group', { name: 'فاقد التعتيق' }).getByLabel(/^قيمة/).fill('20');
  await expect(result(page, 'cost_loss')).toContainText('23.6315');
  await expect(result(page, 'cost_direct_total')).toContainText('135.1576');
  await expect(result(page, 'loss_total_pct')).toContainText('20.000');
});

test('L-04: a stage with no loss value is missing, never zero; the losses of a formula version come from its lab trials', async ({ page }) => {
  await fillPerfumeCostCase(page, 'typed');
  await page.getByRole('group', { name: 'فاقد الفلترة' }).getByLabel(/^قيمة/).fill('');
  await expect(page.getByTestId('missing')).toContainText('فاقد الفلترة');
  await expect(result(page, 'cost_direct_total')).toHaveCount(0);

  // The demo version's trial measured 2 %, 1 % and 3 %: yield 0.98 x 0.99 x 0.97 = 0.941094, total loss 5.8906 %.
  await fillPerfumeCostCase(page, 'lab');
  await expect(result(page, 'loss_total_pct')).toContainText('5.891');
  await expect(result(page, 'loss_yield')).toContainText('0.9411');
  await expect(page.getByRole('group', { name: 'فاقد التعتيق' }).getByLabel(/^قيمة/)).toHaveValue('2');
});

test('L-10: a missing sprayer price and a missing fill are reported, not costed as zero', async ({ page }) => {
  await fillPerfumeCostCase(page);
  await page.getByRole('group', { name: 'سعر البخاخ' }).getByLabel(/^قيمة/).fill('');
  await expect(page.getByTestId('missing')).toContainText('سعر');
  await expect(result(page, 'cost_direct_total')).toHaveCount(0);
});

test('L-05: room spray masses by hand, the clarity is unknown until a trial measured it, and the ratio is never built in', async ({ page }) => {
  await fillRoomSprayCase(page);

  // 1000 g, oil 2 %, ratio 3, other 0.5 %: solubilizer 6 % = 60 g, water 91.5 % = 915 g, oil 20 g.
  await expect(result(page, 'oil_mass')).toContainText('20.000');
  await expect(result(page, 'water_mass')).toContainText('915.000');
  await expect(result(page, 'solubilizer_pct')).toContainText('6.000');
  await expect(result(page, 'water_pct')).toContainText('91.500');
  await expect(result(page, 'clarity_status')).toContainText('غير معروف');
  await expect(result(page, 'clarity_status')).not.toContainText('نجح');
  await expect(page.getByTestId('warnings')).toContainText('وهذا ليس نجاحاً');

  // A hazy trial fails, a clear one passes.
  await pickOption(page, page.getByRole('combobox', { name: /^الوضوح الملاحَظ/ }), 'عكر');
  const clarity = page.getByRole('group', { name: 'مصدر قياس الوضوح' });
  await pickRecord(page, clarity.getByRole('combobox', { name: /^مصدر/ }), DEMO_COA);
  await pickOption(page, clarity.getByRole('combobox', { name: /^ثقة/ }), CONFIDENCE_COA);
  await expect(result(page, 'clarity_status')).toContainText('فشل');
  await pickOption(page, page.getByRole('combobox', { name: /^الوضوح الملاحَظ/ }), 'رائق');
  await expect(result(page, 'clarity_status')).toContainText('نجح');

  // No ratio, no result: the ratio is a missing figure.
  await page.getByRole('group', { name: 'نسبة المذيب إلى العطر' }).getByLabel(/^قيمة/).fill('');
  await expect(page.getByTestId('missing')).toContainText('نسبة المذيب');
  await expect(result(page, 'solubilizer_mass')).toHaveCount(0);
});

test('the existing fragrance modes still work next to the new ones', async ({ page }) => {
  await fillFragranceCase(page);
  await expect(result(page, 'water_mass')).toContainText('395.000');
  await page.getByRole('button', { name: 'تكلفة العطر' }).click();
  await expect(page.getByLabel('زيت العطر', { exact: true })).toHaveValue('20');
});

test.describe.serial('L-01: the fragrance oil tab of a material', () => {
  test('what is on file and what is missing, then a flash point, a certificate and a limit read from it', async ({ page }) => {
    await page.goto('/materials?search=' + encodeURIComponent('Demo Fragrance Oil'));
    await page.getByText('Demo Fragrance Oil').first().click();
    await page.getByRole('tab', { name: /زيت العطر/ }).click();

    const checks = page.getByTestId('fragrance-checks');
    await expect(checks.locator('[data-check="certificate"]')).toHaveAttribute('data-present', 'true');
    await expect(checks.locator('[data-check="ifra_limits"]')).toHaveAttribute('data-present', 'true');
    for (const key of ['supplier', 'density', 'flash_point', 'price']) {
      await expect(checks.locator(`[data-check="${key}"]`)).toHaveAttribute('data-present', 'false');
    }
    await expect(page.getByTestId('fragrance-panel')).toContainText('DEMO-CERT-9');
    await expect(page.getByTestId('fragrance-panel')).toContainText('Demo category A');

    // A flash point is a registered property in °C.
    await page.getByRole('tab', { name: /الخواص/ }).click();
    await page.getByRole('button', { name: 'إضافة قيمة خاصية' }).first().click();
    const property = page.getByRole('dialog');
    await pickRecord(page, property.getByRole('combobox', { name: /^الخاصية/ }), 'نقطة الوميض');
    await expect(property.getByRole('combobox', { name: /^الوحدة/ })).toContainText('°C');
    await property.getByLabel(/^القيمة/).fill('70');
    await pickRecord(page, property.getByRole('combobox', { name: /المصدر/ }), DEMO_SOURCE);
    await pickOption(page, property.getByRole('combobox', { name: /درجة الثقة/ }), CONFIDENCE_TDS);
    await fillDate(property, /سارية من/, '2026-03-01');
    await property.getByRole('button', { name: 'إضافة القيمة' }).click();
    await expect(page.locator('[data-property="flash_point"]')).toContainText('70');

    // A certificate, then a category limit read from it: the limit takes the certificate's source and date.
    await page.getByRole('tab', { name: /زيت العطر/ }).click();
    await expect(checks.locator('[data-check="flash_point"]')).toHaveAttribute('data-present', 'true');
    await page.getByRole('button', { name: 'إضافة شهادة' }).first().click();
    const certificate = page.getByRole('dialog');
    await certificate.getByLabel(/^رقم الشهادة/).fill('SPEC-CERT-1');
    await certificate.getByLabel(/^تعديل IFRA/).fill('Spec amendment');
    await fillDate(certificate, /^تاريخ الإصدار/, '2026-04-01');
    await pickRecord(page, certificate.getByRole('combobox', { name: /المصدر/ }), DEMO_COA);
    await pickOption(page, certificate.getByRole('combobox', { name: /مستوى الثقة/ }), CONFIDENCE_COA);
    await certificate.getByRole('button', { name: 'إضافة الشهادة' }).click();
    await expect(page.getByTestId('fragrance-panel')).toContainText('SPEC-CERT-1');

    const row = page.getByRole('row').filter({ hasText: 'SPEC-CERT-1' });
    await row.getByRole('button', { name: 'إضافة حد فئة' }).click();
    const limit = page.getByRole('dialog');
    await expect(limit.getByRole('combobox', { name: /^شهادة IFRA/ })).toContainText('SPEC-CERT-1');
    await limit.getByLabel(/^الفئة/).fill('Spec category');
    await limit.getByLabel(/^القيمة/).fill('3.5');
    await limit.getByRole('button', { name: 'إضافة القيمة' }).click();
    await expect(page.getByTestId('fragrance-panel').getByRole('row').filter({ hasText: 'SPEC-CERT-1' })).toContainText('Spec category');
    await expect(page.getByTestId('fragrance-panel').getByRole('row').filter({ hasText: 'SPEC-CERT-1' })).toContainText('3.5');
  });

  test('a certificate with limits cannot be removed, and the same category twice is refused', async ({ page }) => {
    await page.goto('/materials?search=' + encodeURIComponent('Demo Fragrance Oil'));
    await page.getByText('Demo Fragrance Oil').first().click();
    await page.getByRole('tab', { name: /زيت العطر/ }).click();

    const row = page.getByRole('row').filter({ hasText: 'SPEC-CERT-1' });
    await row.getByRole('button', { name: 'إضافة حد فئة' }).click();
    const limit = page.getByRole('dialog');
    await limit.getByLabel(/^الفئة/).fill('Spec category');
    await limit.getByLabel(/^القيمة/).fill('1');
    await limit.getByRole('button', { name: 'إضافة القيمة' }).click();
    await expect(limit).toContainText('لهذه الفئة بالفعل');
    await limit.getByRole('button', { name: 'إلغاء' }).click();

    await row.getByRole('button', { name: 'حذف هذه الشهادة؟' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'حذف', exact: true }).click();
    await expect(page.getByText(/لا يمكن حذف الشهادة/)).toBeVisible();
    await expect(row).toBeVisible();
  });
});
