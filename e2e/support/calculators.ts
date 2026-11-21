import { expect, type Page } from '@playwright/test';
import { CONFIDENCE_COA, CONFIDENCE_TDS, DEMO_COA, DEMO_SOURCE, fillSourced, result } from './kit';

/**
 * The hand-checked cases the specs and the review screenshots share (values from the engine's own tests):
 *   saponification    500 g of an oil at SAP 200 mgKOH/g, KOH of 90 %, superfat 10 %  =>  100 g (SAP 190..210 => 95 / 105 g)
 *   neutralisation    1000 g LABSA, acid value 180, NaOH 99 %                        =>  129.615 g
 *   fine fragrance    1000 g, 20 % oil, 80 %w/w alcohol to the OIML 40.0 row         =>  395 / 395 g (see the engine's fine-fragrance test)
 */
export async function fillSoapCase(page: Page): Promise<void> {
  await page.goto('/calculators/saponification');
  await expect(page.getByRole('heading', { level: 1, name: 'التصبن' })).toBeVisible();
  await page.getByRole('button', { name: 'KOH' }).click();
  await fillSourced(page, 'نقاوة القلوي', '90', DEMO_COA, CONFIDENCE_COA);
  await page.getByLabel('الزيت الزائد (Superfat)').fill('10');
  await page.getByLabel('قيمة طريقة الماء').fill('2');
  await page.getByLabel('كتلة الزيت 1', { exact: true }).fill('500');
  await fillSourced(page, 'رقم التصبن للزيت 1', '200', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByLabel('أدنى SAP للزيت 1').fill('190');
  await page.getByLabel('أقصى SAP للزيت 1').fill('210');
  await expect(result(page, 'lye_used')).toContainText('100.000');
}

export async function fillNeutralizationCase(page: Page): Promise<void> {
  await page.goto('/calculators/neutralization');
  await expect(page.getByRole('heading', { level: 1, name: 'تعادل السلفونيك' })).toBeVisible();
  await page.getByLabel('كتلة الحمض', { exact: true }).fill('1000');
  await fillSourced(page, 'رقم الحموضة', '180', DEMO_COA, CONFIDENCE_COA);
  await fillSourced(page, 'نقاوة القلوي الصلب', '99', DEMO_COA, CONFIDENCE_COA);
  await expect(result(page, 'reagent_mass')).toContainText('129.615');
}

export async function fillFragranceCase(page: Page): Promise<void> {
  await page.goto('/calculators/fragrance');
  await expect(page.getByRole('heading', { level: 1, name: 'العطور والمعطرات' })).toBeVisible();
  await page.getByLabel('زيت العطر', { exact: true }).fill('20');
  await page.getByLabel('المثبت', { exact: true }).fill('1');
  await fillSourced(page, 'تركيز الكحول المتاح', '80', DEMO_COA, CONFIDENCE_COA);
  // The stock strength is on a %w/w basis (the unit select of the group), the target on %v/v.
  const stock = page.getByRole('group', { name: 'تركيز الكحول المتاح' });
  await stock.getByRole('combobox', { name: /^وحدة/ }).click();
  await page.getByRole('option', { name: '% وزناً' }).click();
  await page.getByLabel('تركيز الكحول المستهدف', { exact: true }).fill('47.3947631607');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1000');
  await fillSourced(page, 'حد IFRA من الشهادة', '25', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'alcohol_mass')).toContainText('395.000');
}
