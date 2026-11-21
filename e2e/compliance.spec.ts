import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, expectRtlAndFits, fillDate, pickOption, pickRecord, seedComplianceDemo, seedFormulationDemo, settle, stamp } from './support/kit';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedFormulationDemo();
  seedComplianceDemo();
});

const suffix = stamp();
const setCode = `e2e-set-${suffix}`;
const productType = `e2e_product_${suffix}`;
const cleanserName = `Zz Cmpl Spec Cleanser ${suffix}`;
const waterName = `Zz Cmpl Spec Water ${suffix}`;
const formulaCode = `CMPL-E2E-${suffix}`.toUpperCase();
const COA = 'Demo composition certificate';

const materialBox = (page: Page, line: number) => page.getByRole('combobox', { name: `خامة السطر ${line}` });
const percentBox = (page: Page, line: number) => page.getByRole('textbox', { name: `نسبة السطر ${line}` });

/** Adds one component to the material open on its Composition tab. */
async function addComponent(page: Page, { cas, inci, pct }: { cas?: string; inci?: string; pct: string }): Promise<void> {
  await page.getByTestId('add-component').click();
  const dialog = page.getByRole('dialog');
  if (cas) {
    await dialog.getByLabel(/^رقم CAS/).fill(cas);
  }
  if (inci) {
    await dialog.getByLabel(/^اسم INCI/).fill(inci);
  }
  await dialog.getByLabel(/^النسبة في الخامة/).fill(pct);
  await pickRecord(page, dialog.getByRole('combobox', { name: /^المصدر/ }), COA);
  await pickOption(page, dialog.getByRole('combobox', { name: /درجة الثقة/ }), 'شهادة تحليل الدفعة (COA)');
  await fillDate(dialog, /سارية من/, '2026-03-01');
  await dialog.getByRole('button', { name: 'إضافة المكوّن' }).click();
  await expect(page.getByText('تمت إضافة المكوّن.')).toBeVisible();
}

async function createMaterial(page: Page, name: string): Promise<void> {
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('الاسم التجاري').fill(name);
  await dialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
  await page.getByRole('tab', { name: /التركيب/ }).click();
  await expect(page.getByTestId('composition-unknown')).toBeVisible();
}

test('compliance: create a draft rule set, add a sourced rule, publish it and see its hash', async ({ page }) => {
  await page.goto('/compliance');
  await expect(page.getByRole('heading', { level: 1, name: 'المطابقة' })).toBeVisible();
  await expectRtlAndFits(page);

  await page.getByTestId('new-rule-set').first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel(/^الكود/).fill(setCode);
  await dialog.getByLabel(/^النسخة/).fill('1.0.0');
  await dialog.getByLabel(/^الاسم/).fill(`E2E rule set ${suffix}`);
  await dialog.getByRole('button', { name: 'إنشاء المسودة' }).click();
  await expect(page).toHaveURL(/\/compliance\/[0-9a-f-]{36}$/);
  await expect(page.getByText('تم إنشاء مجموعة القواعد كمسودة.')).toBeVisible();
  await expect(page.getByTestId('validation-card')).toContainText('لا توجد قواعد فعّالة بعد');

  // A rule with a missing source is refused by the engine's validator in the browser, before anything is sent.
  await page.getByTestId('add-rule').click();
  const rule = page.getByRole('dialog');
  await rule.getByLabel(/^معرّف القاعدة/).fill('test-0001-max');
  await rule.getByRole('combobox', { name: /^أنواع المنتجات/ }).fill(productType);
  await page.keyboard.press('Enter');
  await rule.getByLabel(/^رقم CAS/).fill('TEST-0001');
  await rule.getByLabel(/^الحد/).fill('0.5');
  await rule.getByRole('button', { name: 'إضافة القاعدة' }).click();
  await expect(rule.getByText('القاعدة بلا مصدر.')).toBeVisible();

  // With a source and a clause it goes through.
  await pickRecord(page, rule.getByRole('combobox', { name: /^مصدر القاعدة/ }), 'Demo test standard');
  await rule.getByLabel(/^مرجع البند/).fill('Clause 1');
  await rule.getByLabel(/^نص القاعدة/).fill('TEST-0001 must stay at or below 0.5%');
  await rule.getByRole('button', { name: 'إضافة القاعدة' }).click();
  await expect(page.getByText('تمت إضافة القاعدة.')).toBeVisible();

  const row = page.locator('[data-rule="test-0001-max"]');
  await expect(row).toBeVisible();
  await expect(row).toContainText('TEST-0001 must stay at or below 0.5%');
  const tableRow = page.getByRole('row').filter({ has: row });
  await expect(tableRow).toContainText('Demo test standard');
  await expect(tableRow).toContainText('0.5');
  await expect(page.getByTestId('validation-ok')).toBeVisible();

  // Publishing: the confirmation shows the hash the engine computes in the browser; the server stores the same one.
  await page.getByTestId('publish').click();
  const hash = ((await page.getByTestId('publish-hash').textContent()) ?? '').trim();
  expect(hash).toMatch(/^[0-9a-f]{64}$/);
  await page.getByTestId('publish-confirm').click();
  await expect(page.getByText('تم نشر مجموعة القواعد وتجميدها.')).toBeVisible();
  await expect(page.getByTestId('rule-set-hash')).toHaveText(hash);
  await expect(page.getByText('مجموعة منشورة وغير قابلة للتعديل')).toBeVisible();
  await expect(page.getByTestId('add-rule')).toHaveCount(0);
  await expect(page.getByTestId('publish')).toHaveCount(0);
  await expectRtlAndFits(page);

  // A published rule set is changed only through a new draft version.
  await page.getByTestId('new-version').click();
  await page.getByRole('dialog').getByLabel(/^النسخة/).fill('1.1.0');
  await page.getByRole('dialog').getByRole('button', { name: 'إنشاء المسودة' }).click();
  await expect(page.getByText('تم إنشاء نسخة جديدة كمسودة.')).toBeVisible();
  await expect(page.getByTestId('publish')).toBeVisible();
  await expect(page.locator('[data-rule="test-0001-max"]')).toBeVisible();
});

test('the rule sets list: search, status filter and product type filter live in the address and clear together', async ({ page }) => {
  await page.goto('/compliance');
  await expect(page.getByRole('row').filter({ hasText: setCode }).first()).toBeVisible();

  await page.getByRole('searchbox').fill(setCode);
  await expect(page).toHaveURL(new RegExp(`search=${setCode}`));
  // The published 1.0.0 and the draft 1.1.0 of the code.
  await expect(page.getByRole('row').filter({ hasText: setCode })).toHaveCount(2);

  await page.goto(`/compliance?search=${setCode}&status=published`);
  await expect(page.getByRole('row').filter({ hasText: setCode })).toHaveCount(1);
  await page.goto(`/compliance?search=${setCode}&product_type=${productType}`);
  await expect(page.getByRole('row').filter({ hasText: setCode })).toHaveCount(2);
  await page.goto(`/compliance?search=${setCode}&product_type=no_such_type`);
  await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر')).toBeVisible();
  await page.getByRole('button', { name: 'مسح كل الفلاتر' }).first().click();
  await expect(page).not.toHaveURL(/product_type|search/);
});

test('materials: the Composition tab starts unknown, takes sourced components and totals them', async ({ page }) => {
  await createMaterial(page, cleanserName);
  await addComponent(page, { cas: 'TEST-0001', inci: 'CMPL TEST INGREDIENT', pct: '10' });
  await addComponent(page, { cas: 'TEST-0010', inci: 'CMPL FILLER', pct: '90' });
  await expect(page.getByTestId('composition-unknown')).toHaveCount(0);
  await expect(page.getByTestId('composition-total')).toContainText('100');
  const first = page.getByRole('row').filter({ hasText: 'TEST-0001' });
  await expect(first).toContainText('CMPL TEST INGREDIENT');
  await expect(first).toContainText('10');
  await expect(first).toContainText(COA);

  // More than 100% is refused by the engine, and the tab says so.
  await addComponent(page, { cas: 'TEST-0009', pct: '5' });
  await expect(page.getByTestId('composition-over')).toBeVisible();
  await page.getByRole('button', { name: /حذف المكوّن TEST-0009/ }).click();
  await page.getByRole('alertdialog').getByRole('button', { name: 'حذف' }).click();
  await expect(page.getByTestId('composition-over')).toHaveCount(0);

  await createMaterial(page, waterName);
  await addComponent(page, { cas: '7732-18-5', inci: 'AQUA', pct: '100' });
  await page.getByTestId('composition-complete').click();
  await expect(page.getByText('تم الحفظ.')).toBeVisible();
});

test('formula editor: the Compliance panel is PASS, then FAIL, as hand-computed, and UNKNOWN once the composition is gone', async ({ page }) => {
  await page.goto('/formulas/create');
  await settle(page);
  await page.getByLabel(/^الكود/).fill(formulaCode);
  await page.getByLabel(/^الاسم\s*\*?$/).fill(`Cmpl compliance ${suffix}`);
  await page.getByLabel(/^نوع المنتج/).fill(productType);
  await pickRecord(page, materialBox(page, 1), cleanserName);
  await percentBox(page, 1).fill('4');
  await page.getByRole('button', { name: 'إضافة سطر' }).click();
  await pickRecord(page, materialBox(page, 2), waterName);
  await page.getByRole('radio', { name: waterName }).click();
  await page.getByRole('button', { name: 'موازنة بـ q.s.' }).click();
  await expect(percentBox(page, 2)).toHaveValue('96');
  await page.getByRole('button', { name: 'إنشاء التركيبة' }).click();
  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  const editorUrl = page.url();

  // 4% of a material holding 10% TEST-0001 is 0.4% of the product: under the 0.5% limit by 0.1.
  const panel = page.getByTestId('compliance-card');
  await expect(panel).toBeVisible();
  const published = panel.getByTestId(`ruleset-${setCode}-1.0.0`);
  await expect(published).toBeVisible();
  await expect(panel.getByTestId('compliance-overall')).toContainText('نجح');
  const result = published.locator('[data-rule-result="test-0001-max"]');
  await expect(result).toHaveAttribute('data-status', 'PASS');
  const resultRow = published.getByRole('row').filter({ has: page.locator('[data-rule-result="test-0001-max"]') });
  await expect(resultRow.getByTestId('rule-value')).toContainText('0.4');
  await expect(resultRow.getByTestId('rule-limit')).toContainText('0.5');
  await expect(resultRow).toContainText('Demo test standard');
  await expect(resultRow).toContainText('0.1');

  // 6% is 0.6%: over the limit by 0.1, live in the same editor, nothing saved.
  await percentBox(page, 1).fill('6');
  await expect(result).toHaveAttribute('data-status', 'FAIL');
  await expect(panel.getByTestId('compliance-overall')).toContainText('فشل');
  await expect(resultRow.getByTestId('rule-value')).toContainText('0.6');
  await expect(resultRow).toContainText('-0.1');
  // 5% is exactly at the limit, which passes (the comparison is at-most).
  await percentBox(page, 1).fill('5');
  await expect(result).toHaveAttribute('data-status', 'PASS');
  await expect(resultRow.getByTestId('rule-value')).toContainText('0.5');
  await percentBox(page, 1).fill('4');
  await expect(result).toHaveAttribute('data-status', 'PASS');

  // Take the composition away: the engine can no longer say, and it says UNKNOWN, never PASS.
  await page.goto('/materials?search=' + encodeURIComponent(cleanserName));
  await page.getByText(cleanserName).first().click();
  await page.getByRole('tab', { name: /التركيب/ }).click();
  for (const name of ['TEST-0001', 'TEST-0010']) {
    await page.getByRole('button', { name: new RegExp(`حذف المكوّن ${name}`) }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'حذف' }).click();
    await expect(page.getByText('تم حذف المكوّن.')).toBeVisible();
  }
  await expect(page.getByTestId('composition-unknown')).toBeVisible();

  await page.goto(editorUrl);
  await expect(panel.getByTestId('compliance-overall')).toContainText('غير معروف');
  await expect(panel.getByTestId('compliance-overall')).not.toContainText('نجح');
  await expect(result).toHaveAttribute('data-status', 'UNKNOWN');
  const gap = panel.getByTestId('composition-gaps');
  await expect(gap).toContainText(cleanserName);
  await expect(gap.getByRole('link', { name: 'استكمال التركيب' })).toHaveAttribute('href', /\/materials\/[0-9a-f-]{36}\?tab=composition/);
  await expect(resultRow.getByTestId('rule-gaps')).toContainText('تركيب الخامة غير مسجل');
});

test('the methanol draft rule is pending a source: UNKNOWN, never PASS, and IFRA reads the oil own certificate', async ({ page }) => {
  await page.goto('/formulas?search=CMPL-FRAGRANCE');
  await page.getByText('CMPL-FRAGRANCE').first().click();
  await expect(page).toHaveURL(/\/versions\/1\/edit/);

  const panel = page.getByTestId('compliance-card');
  const methanol = panel.getByTestId('ruleset-methanol-fragrance-0.1.0');
  await expect(methanol).toBeVisible();
  await expect(methanol.getByTestId('draft-notice')).toBeVisible();
  const rule = methanol.locator('[data-rule-result="methanol-forbidden-alcoholic-fragrance"]');
  await expect(rule).toHaveAttribute('data-status', 'UNKNOWN');
  const row = methanol.getByRole('row').filter({ has: page.locator('[data-rule-result="methanol-forbidden-alcoholic-fragrance"]') });
  await expect(row).toContainText('المصدر معلّق');
  await expect(row).toContainText('مصدر هذه القاعدة لم يصل بعد');
  await expect(methanol.getByTestId('ruleset-overall')).toContainText('غير معروف');

  // IFRA: 0.5% oil against the certificate limit of the saved category.
  const ifra = panel.getByTestId('ifra-block');
  await expect(ifra.getByTestId('ifra-overall')).toContainText('نجح');
  await expect(ifra.locator('[data-ifra-result]')).toContainText('Zz Cmpl Fragrance Oil');
  await expect(ifra.getByTestId('ifra-value')).toContainText('0.9');
  // Another category the certificate has no limit for: UNKNOWN.
  await ifra.getByTestId('ifra-category').fill('Some other category');
  await expect(ifra.getByTestId('ifra-overall')).toContainText('غير معروف');
  await expect(ifra.getByTestId('ifra-gaps')).toContainText('لا يوجد حد في الشهادة لهذه الفئة');
  // No category at all: UNKNOWN too.
  await ifra.getByTestId('ifra-category').fill('');
  await expect(ifra.getByTestId('ifra-overall')).toContainText('غير معروف');
  await expect(ifra.getByTestId('ifra-gaps')).toContainText('فئة IFRA للمنتج غير محددة');
  await ifra.getByTestId('ifra-category').fill('Demo category A');
  await expect(ifra.getByTestId('ifra-overall')).toContainText('نجح');
});

test('label page: INCI order, the 1% marker, merged duplicates, copy, and the pending ES 6910 notice', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.goto('/formulas?search=CMPL-PASS');
  await page.getByText('CMPL-PASS').first().click();
  await page.getByTestId('open-label').click();
  await expect(page).toHaveURL(/\/versions\/1\/label/);
  await expect(page.getByRole('heading', { level: 1, name: 'البطاقة وقائمة INCI' })).toBeVisible();
  await expect(page.getByTestId('es6910-pending')).toContainText('ES 6910');

  // 4% cleanser (10 / 60 / 30) + 96% water: AQUA 96 + 1.2 merged, SLES 2.4, the test ingredient 0.4.
  const items = page.locator('[data-testid="inci-list"] [data-inci]');
  await expect(items).toHaveCount(3);
  await expect(items.nth(0)).toHaveAttribute('data-inci', 'AQUA');
  await expect(items.nth(0)).toContainText('97.2');
  await expect(items.nth(1)).toHaveAttribute('data-inci', 'SODIUM LAURETH SULFATE');
  await expect(items.nth(1)).toContainText('2.4');
  await expect(items.nth(2)).toHaveAttribute('data-inci', 'TEST INGREDIENT');
  await expect(items.nth(2)).toContainText('0.4');
  await expect(items.nth(2)).toHaveAttribute('data-below-one', 'true');
  await expect(items.nth(1)).toHaveAttribute('data-below-one', 'false');
  await expect(page.getByTestId('one-percent-marker')).toHaveCount(1);
  await expect(page.getByTestId('inci-string')).toHaveText('AQUA, SODIUM LAURETH SULFATE, TEST INGREDIENT');

  await page.getByRole('button', { name: 'نسخ قائمة INCI' }).click();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('AQUA, SODIUM LAURETH SULFATE, TEST INGREDIENT');

  // Print view: the shell and the controls drop out, the list stays.
  await page.emulateMedia({ media: 'print' });
  await expect(page.getByTestId('print-label')).toBeHidden();
  await expect(page.locator('.kit-side')).toBeHidden();
  await expect(page.getByTestId('inci-string')).toBeVisible();
  await page.emulateMedia({ media: 'screen' });
  await expectNoHorizontalScroll(page);
});

test('label page: a material with no composition makes the list impossible, and says which', async ({ page }) => {
  await page.goto('/formulas?search=CMPL-UNKNOWN');
  await page.getByText('CMPL-UNKNOWN').first().click();
  await page.getByTestId('open-label').click();

  const gaps = page.getByTestId('inci-gaps');
  await expect(gaps).toBeVisible();
  await expect(gaps).toContainText('Zz Cmpl Mystery Material');
  await expect(gaps).toContainText('تركيب الخامة غير مسجل');
  await expect(gaps.getByRole('link', { name: 'Zz Cmpl Mystery Material' })).toHaveAttribute('href', /\?tab=composition/);
  await expect(page.getByTestId('inci-list')).toHaveCount(0);
  await expect(page.getByTestId('inci-card')).toHaveCount(0);
});

test('editor compliance panel for the UNKNOWN demo lists the material to fill in', async ({ page }) => {
  await page.goto('/formulas?search=CMPL-UNKNOWN');
  await page.getByText('CMPL-UNKNOWN').first().click();
  const panel = page.getByTestId('compliance-card');
  await expect(panel.getByTestId('compliance-overall')).toContainText('غير معروف');
  await expect(panel.getByTestId('composition-gaps')).toContainText('Zz Cmpl Mystery Material');
});
