import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, fillDate, pickOption, pickRecord, seedFormulationDemo, settle, stamp } from './support/kit';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedFormulationDemo();
});

const suffix = stamp();
const materialName = `Spec Glycerin ${suffix}`;

test('create a material, add a property with a new source, add a price and see the audit trail', async ({ page }) => {
  await page.goto('/materials');
  await expect(page.getByRole('heading', { level: 1, name: 'الخامات' })).toBeVisible();

  // 1. The material, from the library's modal.
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const materialDialog = page.getByRole('dialog');
  await materialDialog.getByLabel('الاسم التجاري').fill(materialName);
  await materialDialog.getByLabel('الدرجة', { exact: true }).fill('99.5');
  await materialDialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
  await expect(page.getByRole('heading', { level: 1, name: materialName })).toBeVisible();
  await expect(page.getByText('لا توجد خواص بعد')).toBeVisible();

  // 2. A property value with a source created on the spot.
  await page.getByRole('button', { name: 'إضافة قيمة خاصية' }).first().click();
  const property = page.getByRole('dialog');
  await pickRecord(page, property.getByRole('combobox', { name: /^الخاصية/ }), 'الكثافة');
  await expect(property.getByRole('combobox', { name: /^الوحدة/ })).toContainText('g/mL');
  await property.getByLabel(/^القيمة/).fill('1.26');
  await property.getByRole('button', { name: 'مصدر جديد' }).click();
  const source = page.getByRole('dialog').last();
  await pickOption(page, source.getByRole('combobox', { name: /النوع/ }), 'نشرة بيانات المورد');
  await source.getByLabel('العنوان').fill(`Spec data sheet ${suffix}`);
  await fillDate(source, /تاريخ الحصول عليه/, '2026-03-01');
  await source.getByRole('button', { name: 'إضافة المصدر' }).click();
  await expect(page.getByText('تمت إضافة المصدر.')).toBeVisible();
  await expect(property.getByRole('combobox', { name: /المصدر/ })).toHaveValue(`Spec data sheet ${suffix}`);
  await pickOption(page, property.getByRole('combobox', { name: /درجة الثقة/ }), 'نشرة بيانات المورد (TDS)');
  await fillDate(property, /سارية من/, '2026-03-01');
  await property.getByRole('button', { name: 'إضافة القيمة' }).click();

  const density = page.locator('[data-property="density"]');
  await expect(density).toBeVisible();
  await expect(density).toContainText('1.26');
  await expect(density.getByText('مستخدمة')).toBeVisible();

  // 3. A price: the source is found again by searching.
  await page.getByRole('tab', { name: /الأسعار/ }).click();
  await page.getByRole('button', { name: 'إضافة سعر' }).first().click();
  const price = page.getByRole('dialog');
  await price.getByLabel(/^السعر/).fill('48.75');
  await fillDate(price, /تاريخ العرض/, '2026-03-02');
  const sourceBox = price.getByRole('combobox', { name: /المصدر/ });
  await sourceBox.fill(`Spec data sheet ${suffix}`);
  await page.getByRole('option', { name: new RegExp(`Spec data sheet ${suffix}`) }).click();
  await pickOption(page, price.getByRole('combobox', { name: /درجة الثقة/ }), 'نشرة بيانات المورد (TDS)');
  await price.getByRole('button', { name: 'إضافة السعر' }).click();
  await expect(page.getByText('مستخدم في التكلفة')).toBeVisible();
  await expect(page.getByRole('tabpanel').getByText('48.75')).toBeVisible();

  // 4. Every write is in the audit tab.
  await page.getByRole('tab', { name: /سجل التعديلات/ }).click();
  await expect(page).toHaveURL(/tab=audit/);
  const audit = page.getByRole('tabpanel');
  await expect(audit.getByText('قيمة خاصية').first()).toBeVisible();
  await expect(audit.getByText('سعر', { exact: true }).first()).toBeVisible();
  await expect(audit.getByText('الخامة', { exact: true }).first()).toBeVisible();
  await expect(audit.getByText('إنشاء').first()).toBeVisible();
});

test('the engine picks one value and shows why, with the conflict highlighted', async ({ page }) => {
  await page.goto('/materials?search=Demo SLES');
  await page.getByText('Demo SLES').first().click();
  await page.waitForURL(/\/materials\/[0-9a-f-]{36}/);

  const density = page.locator('[data-property="density"]');
  await expect(density).toBeVisible();
  await expect(density.getByText('القيم متعارضة')).toBeVisible();
  // Three candidates; exactly one is used and the older, weaker one says why it lost.
  await expect(density.getByText('مستخدمة')).toHaveCount(1);
  await expect(density.getByText('ثقة أقل')).toBeVisible();
  await expect(page.locator('[data-property="active_matter"]')).toContainText('68');
  await expect(page.locator('[data-property="active_matter"]')).toContainText('72');
});

test('editing a material saves it in place', async ({ page }) => {
  await page.goto(`/materials?search=${encodeURIComponent(materialName)}`);
  await page.getByText(materialName).first().click();
  await expect(page.getByRole('heading', { level: 1, name: materialName })).toBeVisible();
  await settle(page);
  await page.getByRole('button', { name: 'تعديل' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('الوظيفة').fill('Humectant');
  await dialog.getByRole('button', { name: 'حفظ التعديلات' }).click();
  await expect(page.getByText('تم تحديث الخامة.')).toBeVisible();
  await expect(page.getByText('Humectant').first()).toBeVisible();
});

test('a validation error from the server is shown under the field in Arabic', async ({ page }) => {
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  await page.getByRole('dialog').getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page.getByRole('dialog').getByText('حقل الاسم التجاري مطلوب.')).toBeVisible();
});

test('the library, a material and its tabs fit a phone, in RTL', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/materials');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expectNoHorizontalScroll(page);

  await page.getByText('Demo SLES').first().click();
  await page.waitForURL(/\/materials\/[0-9a-f-]{36}/);
  await settle(page);
  await expectNoHorizontalScroll(page);
  for (const tab of [/الأسعار/, /سجل التعديلات/]) {
    await page.getByRole('tab', { name: tab }).click();
    await expectNoHorizontalScroll(page);
  }
});
