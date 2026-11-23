import { expect, type Page } from '@playwright/test';
import { CONFIDENCE_TDS, DEMO_SOURCE, fillSourced, pickOption, result } from './kit';

/** Opens the fragrance calculator on one of its air-care modes (the mode switch is a segmented control of buttons). */
async function openMode(page: Page, mode: string): Promise<void> {
  await page.goto('/calculators/fragrance');
  await expect(page.getByRole('heading', { level: 1, name: 'العطور والمعطرات' })).toBeVisible();
  await page.getByRole('button', { name: mode, exact: true }).click();
}

/** Aerosol (hand case 1 of the engine test): 150 g can, 20:80 (ratio 4, a supplier TDS), 5 % oil in the concentrate => 120 g gas, 30 g concentrate, 1.5 g oil. */
export async function fillAerosolCase(page: Page): Promise<void> {
  await openMode(page, 'إيروسول');
  await page.getByLabel('زيت العطر في المركّز', { exact: true }).fill('5');
  await fillSourced(page, 'نسبة الغاز الدافع إلى المركّز', '4', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByRole('button', { name: 'ورقة بيانات المورد' }).click();
  await page.getByLabel('صافي التعبئة في العلبة', { exact: true }).fill('150');
  await expect(result(page, 'propellant_mass')).toContainText('120.000');
}

/** Gel air freshener (hand case 2 of the blend test): 1 kg, oil 3 %, gel agent 2.5 % (a TDS dose), other 0.5 %, water q.s. => 30 g, 25 g, 940 g. */
export async function fillGelCase(page: Page): Promise<void> {
  await openMode(page, 'ريد وجل وسيارة');
  await pickOption(page, page.getByRole('combobox', { name: /^المنتج/ }), 'معطر جل');
  await page.getByLabel('زيت العطر', { exact: true }).fill('3');
  await page.getByLabel('إضافات أخرى', { exact: true }).fill('0.5');
  await fillSourced(page, 'مادة التجليل', '2.5', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1000');
  await expect(result(page, 'carrier_water_mass')).toContainText('940.000');
}

/** Candle (hand case 1 of the engine test): 400 g wax, load 25 % of the wax => 100 g fragrance, 500 g candle, 20 % of the candle. */
export async function fillCandleCase(page: Page): Promise<void> {
  await openMode(page, 'شموع وواكس');
  await page.getByLabel('حمل العطر', { exact: true }).fill('25');
  await page.getByLabel('وزن الشمع', { exact: true }).fill('400');
  await expect(result(page, 'fragrance_mass')).toContainText('100.000');
}

export { openMode };
