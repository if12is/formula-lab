import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, seedFormulationDemo, settle, stamp } from './support/kit';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedFormulationDemo();
});

const suffix = stamp();
const code = `SPEC-${suffix}`.toUpperCase();
const fragrance = `Spec Fragrance ${suffix}`;

const materialBox = (page: import('@playwright/test').Page, line: number) => page.getByRole('combobox', { name: `خامة السطر ${line}` });
const percentBox = (page: import('@playwright/test').Page, line: number) => page.getByRole('textbox', { name: `نسبة السطر ${line}` });

test('create a formula with three lines: live sum, a new draft material, q.s. balance, then new versions with a reason and a diff', async ({ page }) => {
  await page.goto('/formulas/create');
  await expect(page.getByRole('heading', { level: 1, name: 'تركيبة جديدة' })).toBeVisible();
  await settle(page);

  await page.getByLabel(/^الكود/).fill(code);
  await page.getByLabel(/^الاسم\s*\*?$/).fill(`Spec shampoo ${suffix}`);
  await page.getByLabel(/^نوع المنتج/).fill('shampoo');

  // Line 1: an existing material with a usable price.
  await pickRecord(page, materialBox(page, 1), 'Demo SLES');
  await percentBox(page, 1).fill('12');
  // The sum follows what is typed, straight from the engine.
  await expect(page.getByTestId('sum')).toContainText('12.000');
  await expect(page.getByTestId('deviation')).toContainText('الانحراف');
  await expect(page.getByTestId('deviation')).toContainText('-88.000');

  // Line 2: a material typed in from the editor (D-09): a draft with an estimated price.
  await page.getByRole('button', { name: 'إضافة سطر' }).click();
  await page.getByRole('button', { name: 'خامة جديدة' }).click();
  const modal = page.getByRole('dialog');
  await modal.getByLabel(/^الاسم التجاري/).fill(fragrance);
  await modal.getByLabel(/^سعر الكيلو/).fill('420');
  await modal.getByRole('button', { name: 'إضافة واستخدام' }).click();
  await expect(page.getByText('تمت إضافة الخامة كمسودة.')).toBeVisible();
  await expect(materialBox(page, 2)).toHaveValue(fragrance);
  await expect(page.getByTestId('line').nth(1).getByText('خامة مسودة')).toBeVisible();
  await percentBox(page, 2).fill('0.5');
  await expect(page.getByTestId('sum')).toContainText('12.500');

  // The estimated price is never used silently: the cost says so, and lists the material with a link to add a real price.
  await expect(page.getByTestId('estimate-warning')).toContainText('التكلفة غير موثوقة: سعر تقديري');
  await expect(page.getByTestId('cost-per-kg')).toContainText('—');
  const missing = page.getByTestId('missing-prices');
  await expect(missing).toContainText(fragrance);
  await expect(missing.getByRole('link', { name: 'إضافة سعر' }).first()).toHaveAttribute('href', /\/materials\/[0-9a-f-]{36}\?tab=prices/);

  // Line 3: water balances the formula (q.s.).
  await page.getByRole('button', { name: 'إضافة سطر' }).click();
  await pickRecord(page, materialBox(page, 3), 'Demo Water');
  await page.getByRole('radio', { name: 'Demo Water' }).click();
  await expect(percentBox(page, 3)).toHaveAttribute('readonly', '');
  await page.getByRole('button', { name: 'موازنة بـ q.s.' }).click();
  await expect(percentBox(page, 3)).toHaveValue('87.5');
  await expect(page.getByTestId('sum')).toContainText('100.000');
  await expect(page.getByTestId('deviation')).toContainText('المجموع 100');

  // Saving creates version 1.
  await page.getByRole('button', { name: 'إنشاء التركيبة' }).click();
  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  await expect(page.getByText('تم إنشاء التركيبة.')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: `Spec shampoo ${suffix}` })).toBeVisible();
  await expect(page.getByTestId('history')).toContainText('النسخة الأولى');
  await expect(percentBox(page, 3)).toHaveValue('87.5');

  // Edit: more SLES, water re-balanced. No reason, no save; the server explains.
  await settle(page);
  await percentBox(page, 1).fill('13');
  await expect(page.getByTestId('deviation')).toContainText('الانحراف');
  await page.getByRole('button', { name: 'موازنة بـ q.s.' }).click();
  await expect(percentBox(page, 3)).toHaveValue('86.5');
  await page.getByRole('button', { name: 'حفظ كنسخة جديدة' }).click();
  await expect(page.getByText('حقل السبب مطلوب.')).toBeVisible();

  await page.getByLabel(/^سبب التعديل/).fill('More foam');
  await page.getByRole('button', { name: 'حفظ كنسخة جديدة' }).click();
  await expect(page).toHaveURL(/\/versions\/2\/edit/);
  await expect(page.getByText('تم حفظ النسخة 2.')).toBeVisible();
  const history = page.getByTestId('history');
  await expect(history).toContainText('More foam');
  await expect(history).toContainText('موازنة q.s.: Demo Water');
  await expect(history).toContainText('نسخة 1');

  // The diff between the two versions.
  await page.getByRole('link', { name: 'مقارنة النسخ' }).click();
  await expect(page).toHaveURL(/\/compare/);
  const changed = page.locator('[data-diff="changed"]');
  await expect(changed).toHaveCount(2);
  await expect(changed.filter({ hasText: 'Demo SLES' })).toContainText('12');
  await expect(changed.filter({ hasText: 'Demo SLES' })).toContainText('13');
  await expect(page.getByTestId('diff')).toContainText('More foam');
});

test('any two versions can be compared and the choice lives in the address', async ({ page }) => {
  await page.goto('/formulas?search=DEMO-SHAMPOO');
  await page.getByText('DEMO-SHAMPOO').first().click();
  await expect(page).toHaveURL(/\/versions\/3\/edit/);
  await page.getByRole('link', { name: 'مقارنة النسخ' }).click();
  await expect(page).toHaveURL(/\/compare/);
  await settle(page);

  // Default: the last two versions (2 -> 3).
  await expect(page.locator('[data-diff="changed"]')).toHaveCount(2);
  await expect(page.locator('[data-diff="changed"]').filter({ hasText: 'Demo Fragrance' })).toContainText('0.3');
  await expect(page.locator('[data-diff="changed"]').filter({ hasText: 'Demo Water' })).toContainText('84');

  // 1 -> 3 adds the fragrance and changes more lines.
  await page.goto(`${new URL(page.url()).pathname}?from=1&to=3`);
  await expect(page.locator('[data-diff="added"]')).toContainText('Demo Fragrance');
  await expect(page.locator('[data-diff="changed"]').filter({ hasText: 'Demo SLES' })).toContainText('14');

  // Changing a version in the picker rewrites the address and keeps the other.
  await page.getByRole('combobox', { name: 'من النسخة' }).click();
  await page.getByRole('option', { name: /النسخة 2/ }).click();
  await expect(page).toHaveURL(/from=2/);
  await expect(page).toHaveURL(/to=3/);
});

test('an approved or older version is read-only until a new version is started from it', async ({ page }) => {
  await page.goto('/formulas?search=DEMO-SHAMPOO');
  await page.getByText('DEMO-SHAMPOO').first().click();
  await expect(page).toHaveURL(/\/versions\/3\/edit/);
  const base = new URL(page.url()).pathname.replace(/\/versions\/3\/edit$/, '');

  await page.goto(`${base}/versions/1/edit`);
  await expect(page.getByText('نسخة معتمدة').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'حفظ كنسخة جديدة' })).toHaveCount(0);
  await expect(percentBox(page, 1)).toHaveAttribute('readonly', '');
  await expect(page.getByLabel(/^سبب التعديل/)).toHaveCount(0);

  await page.getByRole('button', { name: 'نسخة جديدة من هذه' }).click();
  await expect(percentBox(page, 1)).not.toHaveAttribute('readonly', '');
  await expect(page.getByRole('button', { name: 'حفظ كنسخة جديدة' })).toBeVisible();
  await expect(page.getByLabel(/^سبب التعديل/)).toBeVisible();
});

test('the formulas list keeps its filters in the address across a reload', async ({ page }) => {
  await page.goto('/formulas?search=DEMO');
  await expect(page.getByRole('heading', { level: 1, name: 'التركيبات' })).toBeVisible();
  await expect(page.locator('tbody tr')).toHaveCount(1);

  await page.locator('[data-filter-controls]').getByRole('button', { name: 'حالة آخر نسخة' }).click();
  await page.getByRole('menuitemradio', { name: 'مسودة' }).click();
  await expect(page).toHaveURL(/latest_status=draft/);
  await expect(page.locator('tbody tr')).toHaveCount(1);

  // A reload keeps the search and the filter: the address is the state.
  await page.reload();
  await expect(page).toHaveURL(/latest_status=draft/);
  await expect(page.getByRole('searchbox')).toHaveValue('DEMO');
  await expect(page.getByText('الفلاتر النشطة: 2')).toBeVisible();
  await expect(page.locator('tbody tr')).toHaveCount(1);

  // Nothing matches: the filtered-empty state offers the way out.
  await page.getByRole('searchbox').fill('zzz-no-match');
  await expect(page).toHaveURL(/search=zzz-no-match/);
  await expect(page.getByRole('heading', { name: 'لا توجد نتائج تطابق هذه الفلاتر' })).toBeVisible();
  await page.getByRole('button', { name: 'مسح كل الفلاتر' }).click();
  await expect(page).not.toHaveURL(/latest_status|search=/);
  await expect(page.locator('tbody tr').first()).toBeVisible();
});

test('the materials library keeps its filters, with a filtered-empty state, across a reload', async ({ page }) => {
  await page.goto('/materials?search=Demo&status=active&sort=trade_name&dir=desc');
  await expect(page.locator('tbody tr').first()).toContainText('Demo Water');
  await expect(page.getByText('الفلاتر النشطة: 2')).toBeVisible();

  await page.locator('[data-filter-controls]').getByRole('button', { name: 'مربوطة بسند' }).click();
  await expect(page).toHaveURL(/has_sanad_link=1/);
  await expect(page.getByRole('heading', { name: 'لا توجد نتائج تطابق هذه الفلاتر' })).toBeVisible();

  await page.reload();
  await expect(page).toHaveURL(/has_sanad_link=1/);
  await expect(page).toHaveURL(/status=active/);
  await expect(page.getByRole('searchbox')).toHaveValue('Demo');
  await expect(page.getByRole('heading', { name: 'لا توجد نتائج تطابق هذه الفلاتر' })).toBeVisible();
});

test('the editor works in dark mode and fits a phone, in RTL', async ({ page }) => {
  await page.goto('/formulas?search=DEMO-SHAMPOO');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await page.getByText('DEMO-SHAMPOO').first().click();
  await expect(page).toHaveURL(/\/versions\/3\/edit/);
  const editor = page.url();

  await page.getByRole('button', { name: 'العرض' }).click();
  await page.getByRole('menuitemradio', { name: 'داكن' }).click();
  await page.keyboard.press('Escape');
  await expect(page.locator('html')).toHaveAttribute('data-mode', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-mode', 'dark');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(editor);
  await settle(page);
  await expectNoHorizontalScroll(page);
  await expect(page.getByTestId('totals-bar')).toBeVisible();

  await page.goto(editor.replace(/\/versions\/3\/edit$/, '/compare?from=1&to=3'));
  await settle(page);
  await expectNoHorizontalScroll(page);
  await page.goto('/formulas');
  await settle(page);
  await expectNoHorizontalScroll(page);
  await page.goto('/formulas/create');
  await settle(page);
  await expectNoHorizontalScroll(page);

  // Back to the default theme so later specs start from it.
  await page.getByRole('button', { name: 'العرض' }).click();
  await page.getByRole('menuitemradio', { name: 'فاتح' }).click();
});
