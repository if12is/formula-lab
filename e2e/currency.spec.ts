import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, expectRtlAndFits, fillDate, pickOption, pickRecord, resetExchangeRates, seedFormulationDemo, setDisplayCurrency, setTheme, settle, stamp } from './support/kit';

/**
 * Multi-currency with EGP as the base: a USD price has no rate, so the cost says "missing" and points at the rate screen;
 * adding a dated rate makes the cost appear with the rate in the explain panel; the display currency shows a second figure
 * beside EGP. Serial: one story, one set of rows. Then the review screenshots (light/dark, desktop/390px) of that story.
 * Evidence goes to orchestration/evidence/FX/.
 */
test.describe.configure({ mode: 'serial' });

const suffix = stamp();
const materialName = `FX Spec ${suffix}`;
const sourceTitle = `CBE ${suffix}`;
const EVIDENCE = 'orchestration/evidence/FX';
const state = { formulaUrl: '' };

const materialBox = (page: Page) => page.getByRole('combobox', { name: 'خامة السطر 1' });
const percentBox = (page: Page) => page.getByRole('textbox', { name: 'نسبة السطر 1' });

/** Adds a rate in the rate modal that is already open; the source is created on the spot the first time, found by search after. */
async function fillRate(page: Page, values: { currency?: string; rate: string; date: string; createSource?: boolean }): Promise<void> {
  const dialog = page.getByRole('dialog');
  if (values.currency) {
    await pickOption(page, dialog.getByRole('combobox', { name: /^العملة/ }), values.currency);
  }
  await dialog.getByLabel(/^السعر بالجنيه/).fill(values.rate);
  await fillDate(dialog, /تاريخ السعر/, values.date);
  await pickOption(page, dialog.getByRole('combobox', { name: /درجة الثقة/ }), 'قاعدة بيانات تنظيمية');
  if (values.createSource) {
    await dialog.getByRole('button', { name: 'مصدر جديد' }).click();
    const source = page.getByRole('dialog').last();
    await pickOption(page, source.getByRole('combobox', { name: /النوع/ }), 'قاعدة بيانات تنظيمية');
    await source.getByLabel('العنوان').fill(sourceTitle);
    await fillDate(source, /تاريخ الحصول عليه/, '2026-03-01');
    await source.getByRole('button', { name: 'إضافة المصدر' }).click();
    await expect(page.getByText('تمت إضافة المصدر.')).toBeVisible();
  } else {
    await pickRecord(page, dialog.getByRole('combobox', { name: /المصدر/ }), sourceTitle);
  }
}

test.beforeAll(() => {
  seedFormulationDemo();
  resetExchangeRates();
});

test('a material price can be quoted in USD; the modal says there is no rate for its date', async ({ page }) => {
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const materialDialog = page.getByRole('dialog');
  await materialDialog.getByLabel('الاسم التجاري').fill(materialName);
  await materialDialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);

  await page.getByRole('tab', { name: /الأسعار/ }).click();
  await page.getByRole('button', { name: 'إضافة سعر' }).first().click();
  const price = page.getByRole('dialog');
  // EGP is the default currency.
  await expect(price.getByRole('combobox', { name: /^العملة/ })).toContainText('جنيه مصري');
  await pickOption(page, price.getByRole('combobox', { name: /^العملة/ }), 'دولار أمريكي');
  await price.getByLabel(/^السعر/).fill('2');
  await fillDate(price, /تاريخ العرض/, '2026-03-02');
  await expect(price.getByTestId('fx-missing')).toContainText('لا يوجد سعر صرف USD بتاريخ 2026-03-02 أو قبله');
  await expect(price.getByTestId('fx-missing').getByRole('link', { name: 'إضافة سعر صرف' })).toHaveAttribute('href', /new_currency=USD&new_date=2026-03-02/);
  await price.getByRole('combobox', { name: /المصدر/ }).fill('Demo supplier');
  await page.getByRole('option', { name: /Demo supplier data sheet/ }).click();
  await pickOption(page, price.getByRole('combobox', { name: /درجة الثقة/ }), 'نشرة بيانات المورد (TDS)');
  await price.getByRole('button', { name: 'إضافة السعر' }).click();

  // The Prices tab keeps the original currency and says why there is no EGP figure.
  const row = page.getByRole('tabpanel').locator('tbody tr, [data-mobile-table] li').first();
  await expect(row.locator('[data-price-currency="USD"]')).toContainText('2.00 USD');
  await expect(row.getByTestId('price-egp')).toContainText('—');
  await expect(row.getByTestId('price-add-rate')).toContainText('أضف سعر صرف USD بتاريخ 2026-03-02');
});

test('the editor cost is missing without a rate; adding the rate makes it appear, and the explain panel names the rate', async ({ page }) => {
  await page.goto('/formulas/create');
  await settle(page);
  await page.getByLabel(/^الكود/).fill(`FX-${suffix}`.toUpperCase());
  await page.getByLabel(/^الاسم\s*\*?$/).fill(`FX formula ${suffix}`);
  await page.getByLabel(/^نوع المنتج/).fill('shampoo');
  await pickRecord(page, materialBox(page), materialName);
  await percentBox(page).fill('100');

  // The price in USD shows as quoted; its EGP equivalent is missing, never zero.
  const line = page.getByTestId('line').first();
  await expect(line.locator('[data-price-currency="USD"]')).toContainText('2.00 USD');
  await expect(line.getByTestId('line-fx-missing')).toBeVisible();
  await expect(page.getByTestId('cost-per-kg')).toContainText('—');
  const missing = page.getByTestId('missing-prices');
  await expect(missing).toContainText(materialName);
  await expect(missing).toContainText('السعر بعملة أخرى ولا يتوفر سعر صرف مؤرخ.');
  await expect(missing.getByTestId('add-rate-link')).toContainText('إضافة سعر صرف USD بتاريخ 2026-03-02');
  await expect(missing.getByTestId('add-rate-link')).toHaveAttribute('href', /new_currency=USD&new_date=2026-03-02/);
  await expect(page.getByTestId('usd-exposure')).toHaveCount(0);

  await page.getByRole('button', { name: 'إنشاء التركيبة' }).click();
  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  state.formulaUrl = new URL(page.url()).pathname;
  await expect(page.getByTestId('cost-per-kg')).toContainText('—');

  // The link opens the rate screen with the form already pointed at that currency and day.
  await page.getByTestId('add-rate-link').click();
  await expect(page).toHaveURL(/\/settings\/exchange-rates\?new_currency=USD&new_date=2026-03-02/);
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('combobox', { name: /^العملة/ })).toContainText('دولار أمريكي');
  // A rate dated the day before the quote applies to it; rates later than the quote never do.
  await fillRate(page, { rate: '48.5', date: '2026-03-01', createSource: true });
  await dialog.getByRole('button', { name: 'إضافة السعر' }).click();
  await expect(page.getByText('تمت إضافة سعر الصرف.')).toBeVisible();
  const usdTile = page.locator('[data-latest="USD"]');
  await expect(usdTile).toContainText('48.5');
  await expect(usdTile).toContainText('2026-03-01');
  await expect(usdTile).toContainText(sourceTitle);

  // Back in the editor the cost is there: 2 USD x 48.5 = 97 EGP per kg.
  await page.goto(state.formulaUrl);
  await expect(page.getByTestId('cost-per-kg')).toContainText('97.00');
  await expect(page.getByTestId('missing-prices')).toHaveCount(0);
  await expect(page.getByTestId('line').first().locator('[data-price-currency="USD"]')).toContainText('≈ 97.00');

  // Every USD-priced material is USD-linked: the whole cost.
  await expect(page.getByTestId('usd-exposure')).toContainText('100.0');
  await expect(page.getByTestId('bar-usd-exposure')).toContainText('من تكلفة الخامات مرتبطة بالدولار');

  // The explain panel shows the FX step: rate, date, source and confidence.
  await page.getByTestId('cost-card').getByTestId('explain-trigger').first().click();
  const panel = page.getByTestId('explain-panel');
  await expect(panel).toBeVisible();
  const fx = panel.locator('[data-trace-op="input"]').filter({ hasText: 'FX USD->EGP' });
  await expect(fx).toContainText(`FX USD->EGP 2026-03-01 (${sourceTitle})`);
  await expect(fx).toContainText('48.5');
  await expect(fx.getByTestId('trace-source')).toContainText(sourceTitle);
  await expect(fx.getByTestId('trace-source')).toContainText('L4');
  await expect(panel.locator('[data-trace-op="fx"]')).toBeVisible();
});

test('the rate screen: add a later rate, filters, duplicate and zero are refused, edit; the price keeps the rate of its own date', async ({ page }) => {
  await page.goto('/settings/exchange-rates');
  await expect(page.getByRole('heading', { level: 1, name: 'أسعار الصرف' })).toBeVisible();
  await expectRtlAndFits(page);
  await expect(page.locator('[data-latest="EUR"]')).toContainText('لا يوجد سعر بعد');

  await page.getByTestId('add-rate').click();
  await fillRate(page, { currency: 'دولار أمريكي', rate: '50', date: '2026-06-01' });
  await page.getByRole('dialog').getByRole('button', { name: 'إضافة السعر' }).click();
  await expect(page.getByText('تمت إضافة سعر الصرف.')).toBeVisible();
  await expect(page.locator('[data-latest="USD"]')).toContainText('2026-06-01');

  // The same currency, day and source twice is refused, with the reason under the date.
  await page.getByTestId('add-rate').click();
  await fillRate(page, { currency: 'دولار أمريكي', rate: '51', date: '2026-06-01' });
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'إضافة السعر' }).click();
  await expect(dialog).toContainText('بنفس التاريخ 2026-06-01 ونفس المصدر');
  // A rate of zero is refused too.
  await dialog.getByLabel(/^السعر بالجنيه/).fill('0');
  await dialog.getByRole('button', { name: 'إضافة السعر' }).click();
  await expect(dialog).toContainText('يجب أن يكون سعر الصرف أكبر من صفر.');
  await dialog.getByRole('button', { name: 'إلغاء' }).click();

  // Filters live in the address, and a filter with no result says so.
  await page.goto('/settings/exchange-rates?currency=USD&sort=rate&dir=asc');
  const rows = page.locator('tbody tr, [data-mobile-table] li');
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('48.5');
  await page.goto('/settings/exchange-rates?currency=EUR');
  await expect(page.getByText('لا توجد نتائج')).toBeVisible();
  await page.getByRole('button', { name: 'مسح الكل' }).first().click();
  await expect(page).not.toHaveURL(/currency=EUR/);
  await page.goto('/settings/exchange-rates?date_from=2026-05-01');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('2026-06-01');

  // Edit the later rate.
  await page.getByRole('button', { name: 'تعديل سعر الصرف' }).first().click();
  const edit = page.getByRole('dialog');
  await expect(edit.getByLabel(/^السعر بالجنيه/)).toHaveValue('50');
  await edit.getByLabel(/^السعر بالجنيه/).fill('51');
  await edit.getByRole('button', { name: 'حفظ التعديل' }).click();
  await expect(page.getByText('تم تحديث سعر الصرف.')).toBeVisible();
  await expect(rows.first()).toContainText('51');

  // The price quoted on 2026-03-02 still costs with the rate of its own date (48.5), not the newest (51).
  await page.goto(state.formulaUrl);
  await expect(page.getByTestId('cost-per-kg')).toContainText('97.00');
});

test('the display currency shows the converted figure beside EGP, with the rate date and source, and EGP stays primary', async ({ page }) => {
  await page.goto(state.formulaUrl);
  await expect(page.getByTestId('cost-per-kg')).toContainText('97.00');
  await expect(page.getByTestId('display-currency-line')).toHaveCount(0);

  await setDisplayCurrency(page, /دولار أمريكي/);
  await expect(page.getByText('تم حفظ عملة العرض.')).toBeVisible();
  const line = page.getByTestId('cost-card').getByTestId('display-currency-line');
  // 97 / 51 = 1.90196...: the newest rate up to today, not the rate of the price's date.
  await expect(line).toContainText('1.90 USD');
  await expect(line).toContainText('51');
  await expect(line).toContainText('2026-06-01');
  await expect(line).toContainText(sourceTitle);
  await expect(page.getByTestId('cost-per-kg')).toContainText('97.00');
  await expect(page.getByTestId('bar-display-currency')).toContainText('1.90 USD');

  // A currency with no rate says so and links to add one, never a guess.
  await setDisplayCurrency(page, /يورو/);
  await expect(page.getByTestId('cost-card').getByTestId('display-currency-line')).toContainText('لا يوجد سعر صرف EUR بعد');
  await expect(page.getByTestId('cost-card').getByTestId('display-currency-line').getByRole('link', { name: 'إضافة سعر صرف' })).toHaveAttribute('href', /new_currency=EUR/);

  // The choice survives a reload; EGP turns the second line off.
  await page.reload();
  await expect(page.getByTestId('cost-card').getByTestId('display-currency-line')).toContainText('EUR');
  await setDisplayCurrency(page, /جنيه مصري/);
  await expect(page.getByTestId('display-currency-line')).toHaveCount(0);
});

test('a price in a currency with no rate shows no EGP figure and a link to add the rate', async ({ page }) => {
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  await page.getByRole('dialog').getByLabel('الاسم التجاري').fill(`FX EUR Spec ${suffix}`);
  await page.getByRole('dialog').getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
  await page.getByRole('tab', { name: /الأسعار/ }).click();
  await page.getByRole('button', { name: 'إضافة سعر' }).first().click();
  const price = page.getByRole('dialog');
  await pickOption(page, price.getByRole('combobox', { name: /^العملة/ }), 'يورو');
  await price.getByLabel(/^السعر/).fill('9');
  await fillDate(price, /تاريخ العرض/, '2026-04-02');
  await expect(price.getByTestId('fx-missing')).toContainText('EUR');
  await price.getByRole('combobox', { name: /المصدر/ }).fill('Demo supplier');
  await page.getByRole('option', { name: /Demo supplier data sheet/ }).click();
  await pickOption(page, price.getByRole('combobox', { name: /درجة الثقة/ }), 'نشرة بيانات المورد (TDS)');
  await price.getByRole('button', { name: 'إضافة السعر' }).click();
  const eur = page.locator('[data-price-currency="EUR"]').first();
  await expect(eur).toContainText('9.00 EUR');
  await expect(page.getByTestId('price-add-rate').first()).toContainText('أضف سعر صرف EUR بتاريخ 2026-04-02');
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1000 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expectRtlAndFits(page);
          await page.screenshot({ path: `${EVIDENCE}/${name}-${theme}-${device}.png` });
        };

        await page.goto('/settings/exchange-rates');
        await expect(page.getByRole('heading', { level: 1, name: 'أسعار الصرف' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.locator('[data-latest="USD"]')).toContainText('51');
        await shot('exchange-rates');

        await page.goto(state.formulaUrl);
        await expect(page.getByTestId('cost-per-kg')).toContainText('97.00');
        await shot('editor-usd-price');
        await page.getByTestId('cost-card').getByTestId('explain-trigger').first().click();
        await expect(page.getByTestId('explain-panel')).toContainText(`FX USD->EGP 2026-03-01 (${sourceTitle})`);
        await shot('editor-explain-fx');
        await page.keyboard.press('Escape');

        await setDisplayCurrency(page, /دولار أمريكي/);
        await expect(page.getByTestId('cost-card').getByTestId('display-currency-line')).toContainText('1.90 USD');
        await shot('editor-display-currency');
        await setDisplayCurrency(page, /جنيه مصري/);

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}

test('the price modal and the rate form fit a phone, in RTL', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/settings/exchange-rates');
  await settle(page);
  await expectRtlAndFits(page);
  await page.getByTestId('add-rate').click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expectNoHorizontalScroll(page);
});
