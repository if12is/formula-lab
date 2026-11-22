import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_REFERENCE, DEMO_SOURCE, fillSourced, pickOption, pickRecord, result, seedFormulationDemo, settle, stamp } from './support/kit';

/**
 * Phase M, skincare and emulsions: the phase editor (M-01), the functional classes of a material (M-02) and the
 * oil-phase to emulsifier-blend calculator (M-03). Every number is the engine's; the specs check what the page shows.
 */
test.beforeAll(() => {
  seedFormulationDemo();
});

const suffix = stamp();
const materialBox = (page: Page, line: number) => page.getByRole('combobox', { name: `خامة السطر ${line}` });
const percentBox = (page: Page, line: number) => page.getByRole('textbox', { name: `نسبة السطر ${line}` });
const phaseBox = (page: Page, line: number) => page.getByRole('combobox', { name: `طور السطر ${line}` });
const tempBox = (page: Page, line: number) => page.getByRole('textbox', { name: `حرارة إضافة السطر ${line}` });

test('the editor adds up each phase with the engine, refuses phases that miss 100, and saves phases with their temperatures', async ({ page }) => {
  await page.goto('/formulas/create');
  await settle(page);
  await page.getByLabel(/^الكود/).fill(`PH-${suffix}`.toUpperCase());
  await page.getByLabel(/^الاسم\s*\*?$/).fill(`Phase cream ${suffix}`);
  await page.getByLabel(/^نوع المنتج/).fill('cream');

  // No phase chosen yet: the phase card stays out of the way (phases are optional).
  await pickRecord(page, materialBox(page, 1), 'Demo SLES');
  await percentBox(page, 1).fill('25');
  await expect(page.getByTestId('phases-card')).toHaveCount(0);

  await pickOption(page, phaseBox(page, 1), 'الطور الزيتي');
  await tempBox(page, 1).fill('75');
  await expect(page.getByTestId('phases-card')).toBeVisible();

  await page.getByRole('button', { name: 'إضافة سطر' }).click();
  await pickRecord(page, materialBox(page, 2), 'Demo Water');
  await percentBox(page, 2).fill('70');
  // Once one line has a phase, every line needs one: the engine says so under the line.
  await expect(page.getByTestId('line').nth(1)).toContainText('اختر طوراً لهذا السطر');
  await pickOption(page, phaseBox(page, 2), 'الطور المائي');
  await tempBox(page, 2).fill('75');

  // 25 + 70 = 95: the phases do not add up.
  await expect(page.getByTestId('phase-issue')).toContainText('مجموع الأطوار لا يساوي 100');
  await expect(page.locator('[data-phase="oil"]')).toContainText('25.000');
  await expect(page.locator('[data-phase="water"]')).toContainText('70.000');

  await page.getByRole('button', { name: 'إضافة سطر' }).click();
  await pickRecord(page, materialBox(page, 3), 'Demo Salt');
  await percentBox(page, 3).fill('5');
  await pickOption(page, phaseBox(page, 3), 'طور التبريد');
  await tempBox(page, 3).fill('40');
  await expect(page.getByTestId('phase-issue')).toHaveCount(0);
  await expect(page.locator('[data-phase="cool_down"]')).toContainText('5.000');

  // A temperature that is not a number is refused on the line.
  await tempBox(page, 3).fill('hot');
  await expect(page.getByTestId('line').nth(2)).toContainText('اكتب حرارة الإضافة كرقم');
  await tempBox(page, 3).fill('40');

  await page.getByRole('button', { name: 'إنشاء التركيبة' }).click();
  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  await settle(page);

  // Reopened from the server: phases and temperatures are there.
  await expect(phaseBox(page, 1)).toContainText('الطور الزيتي');
  await expect(phaseBox(page, 3)).toContainText('طور التبريد');
  await expect(tempBox(page, 1)).toHaveValue('75');
  await expect(tempBox(page, 3)).toHaveValue('40');
  await expect(page.locator('[data-phase="oil"]')).toContainText('25.000');
});

test('the library shows functional classes, saves several on a material and filters by one', async ({ page }) => {
  const name = `Spec Cetyl ${suffix}`;
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('الاسم التجاري').fill(name);
  await dialog.getByLabel('الفئات الوظيفية').click();
  await page.getByRole('option', { name: 'مُليّن (Emollient)' }).click();
  await page.getByRole('option', { name: 'مكثّف (Thickener)' }).click();
  await page.keyboard.press('Escape');
  await dialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
  await expect(page.getByText('مُليّن (Emollient) · مكثّف (Thickener)')).toBeVisible();

  // The filter writes ?functional_class= and keeps only the material that has the class.
  await page.goto(`/materials?search=${encodeURIComponent(name)}`);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();
  await page.locator('[data-filter-controls]').getByRole('button', { name: 'الفئة الوظيفية' }).click();
  await page.getByRole('menuitemradio', { name: 'مكثّف (Thickener)' }).click();
  await expect(page).toHaveURL(/functional_class=thickener/);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();
  await page.reload();
  await expect(page).toHaveURL(/functional_class=thickener/);
  await page.locator('[data-filter-controls]').getByRole('button', { name: 'الفئة الوظيفية' }).click();
  await page.getByRole('menuitemradio', { name: 'عطر (Fragrance)' }).click();
  await expect(page).toHaveURL(/functional_class=fragrance/);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toHaveCount(0);
});

test('HLB: from the oil phase to two non-ionic emulsifiers, 4.3 and 15.0 for a target of 9, refusing an ionic one', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await page.getByRole('button', { name: 'من الزيت إلى المستحلبين' }).click();

  // (20 x 8 + 10 x 11) / 30 = 9
  await page.getByLabel('كتلة المكوّن 1', { exact: true }).fill('20');
  await fillSourced(page, 'HLB المطلوب للمكوّن 1', '8', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByRole('button', { name: 'إضافة مكوّن زيتي' }).click();
  await page.getByLabel('كتلة المكوّن 2', { exact: true }).fill('10');
  await fillSourced(page, 'HLB المطلوب للمكوّن 2', '11', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await fillSourced(page, 'HLB للمستحلب المنخفض', '4.3', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await fillSourced(page, 'HLB للمستحلب المرتفع', '15.0', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByLabel('كتلة المستحلبين معاً', { exact: true }).fill('107');
  await expect(result(page, 'required_hlb')).toContainText('9.00');
  // f_high = (9 - 4.3) / 10.7 = 47/107: 107 g splits into 47 g high and 60 g low.
  await expect(result(page, 'mass_high')).toContainText('47.000');
  await expect(result(page, 'mass_low')).toContainText('60.000');

  // An ionic emulsifier is refused, not blended.
  await pickOption(page, page.getByTestId('high-ionicity'), 'كاتيوني');
  await expect(page.getByTestId('missing')).toContainText('مزج HLB للمستحلبات غير الأيونية فقط');
  await expect(result(page, 'mass_high')).toHaveCount(0);
});

test('the plain two-emulsifier blend: 4.3 and 15.0 for a target of 10.0 split 57 and 50 in 107 g', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await page.getByRole('button', { name: 'مزج مستحلبين' }).click();
  await page.getByLabel('HLB المستهدف', { exact: true }).fill('10.0');
  await fillSourced(page, 'HLB للمستحلب المنخفض', '4.3', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await fillSourced(page, 'HLB للمستحلب المرتفع', '15.0', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByLabel('كتلة المستحلبين معاً', { exact: true }).fill('107');
  await expect(result(page, 'mass_high')).toContainText('57.000');
  await expect(result(page, 'mass_low')).toContainText('50.000');
});
