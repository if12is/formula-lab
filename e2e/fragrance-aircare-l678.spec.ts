import { expect, test } from '@playwright/test';
import { fillAerosolCase, fillCandleCase, fillGelCase, openMode } from './support/aircare';
import { CONFIDENCE_TDS, DEMO_SOURCE, fillSourced, pickOption, result } from './support/kit';

/**
 * L-06 aerosol, L-07 reed diffuser / fragrance oil / candle and wax, L-08 gel and car fresheners. Every expected figure is the
 * hand computation of the engine's own tests; a limit or ratio nobody sourced is missing or unknown, never a pass.
 */
test.setTimeout(120_000);

test('L-06: aerosol gas mass by hand, the filling-line warning is always shown and the fill check is unknown until a maximum is sourced', async ({ page }) => {
  await fillAerosolCase(page);

  // 150 g, ratio 4 (20:80), 5 % oil: 120 g gas, 30 g concentrate, 1.5 g oil, 1 % oil in the product.
  await expect(result(page, 'concentrate_mass')).toContainText('30.000');
  await expect(result(page, 'oil_mass')).toContainText('1.500');
  await expect(result(page, 'concentrate_pct')).toContainText('20.000');
  await expect(result(page, 'propellant_pct')).toContainText('80.000');
  await expect(result(page, 'oil_pct_product')).toContainText('1.000');
  await expect(page.getByTestId('filling-line-note')).toBeVisible();
  await expect(page.getByTestId('warnings')).toContainText('خط تعبئة مضغوط');
  await expect(result(page, 'fill_status')).toContainText('غير معروف');
  await expect(result(page, 'fill_status')).not.toContainText('نجح');

  // 1000 cans: 120 kg of gas, 30 kg of concentrate.
  await page.getByLabel('عدد العلب في التشغيلة', { exact: true }).fill('1000');
  await expect(result(page, 'run_propellant')).toContainText('120,000.000');
  await expect(result(page, 'run_concentrate')).toContainText('30,000.000');

  // A rated maximum of 140 g fails the 150 g fill by 10 g; 150 g passes.
  await fillSourced(page, 'أقصى تعبئة صافية مقررة للعلبة', '140', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'fill_status')).toContainText('فشل');
  await expect(result(page, 'fill_margin')).toContainText('-10.000');
  await page.getByRole('group', { name: 'أقصى تعبئة صافية مقررة للعلبة' }).getByLabel(/^قيمة/).fill('150');
  await expect(result(page, 'fill_status')).toContainText('نجح');

  // No ratio, no result: nothing is defaulted.
  await page.getByRole('group', { name: 'نسبة الغاز الدافع إلى المركّز' }).getByLabel(/^قيمة/).fill('');
  await expect(page.getByTestId('missing')).toContainText('نسبة الغاز');
  await expect(result(page, 'propellant_mass')).toHaveCount(0);
});

test('L-06: the ratio needs its basis (TDS or trial)', async ({ page }) => {
  await openMode(page, 'إيروسول');
  await page.getByLabel('زيت العطر في المركّز', { exact: true }).fill('5');
  await fillSourced(page, 'نسبة الغاز الدافع إلى المركّز', '4', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByLabel('صافي التعبئة في العلبة', { exact: true }).fill('150');
  await expect(page.getByTestId('missing')).toContainText('مصدر النسبة');
  await expect(result(page, 'propellant_mass')).toHaveCount(0);
});

test('L-07 / L-08: reed diffuser, gel and car blends by hand, the gel dose is a sourced figure', async ({ page }) => {
  // Reed diffuser: 500 g, oil 10 %: 50 g oil, 450 g solvent.
  await openMode(page, 'ريد وجل وسيارة');
  await page.getByLabel('زيت العطر', { exact: true }).fill('10');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('500');
  await expect(result(page, 'oil_mass')).toContainText('50.000');
  await expect(result(page, 'carrier_solvent_mass')).toContainText('450.000');
  await expect(result(page, 'carrier_solvent_pct')).toContainText('90.000');
  await expect(page.getByRole('group', { name: 'مادة التجليل' })).toHaveCount(0);

  // Car freshener: 250 g, oil 20 %: 50 g oil, 200 g carrier.
  await pickOption(page, page.getByRole('combobox', { name: /^المنتج/ }), 'معطر سيارة');
  await page.getByLabel('زيت العطر', { exact: true }).fill('20');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('250');
  await expect(result(page, 'oil_mass')).toContainText('50.000');
  await expect(result(page, 'carrier_solvent_mass')).toContainText('200.000');

  // Gel: 1 kg, oil 3 %, gel agent 2.5 %, other 0.5 %: 30 g, 25 g, water 940 g (94 %).
  await fillGelCase(page);
  await expect(result(page, 'oil_mass')).toContainText('30.000');
  await expect(result(page, 'dose_gelAgent_mass')).toContainText('25.000');
  await expect(result(page, 'carrier_water_pct')).toContainText('94.000');

  // No gel dose, no result.
  await page.getByRole('group', { name: 'مادة التجليل' }).getByLabel(/^قيمة/).fill('');
  await expect(page.getByTestId('missing')).toContainText('مادة التجليل');
  await expect(result(page, 'carrier_water_mass')).toHaveCount(0);
});

test('L-07: candle fragrance load by hand, the wax maximum and the IFRA limit are sourced or unknown', async ({ page }) => {
  await fillCandleCase(page);
  await expect(result(page, 'finished_mass')).toContainText('500.000');
  await expect(result(page, 'candle_pct')).toContainText('20.000');
  await expect(result(page, 'load_status')).toContainText('غير معروف');
  await expect(result(page, 'ifra_status')).toContainText('غير معروف');
  await expect(page.getByTestId('warnings')).toContainText('وهذا ليس نجاحاً');

  await fillSourced(page, 'أقصى حمل عطر للشمع', '30', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'load_status')).toContainText('نجح');
  await expect(result(page, 'load_margin')).toContainText('5.000');
  await page.getByRole('group', { name: 'أقصى حمل عطر للشمع' }).getByLabel(/^قيمة/).fill('20');
  await expect(result(page, 'load_status')).toContainText('فشل');

  await fillSourced(page, 'حد IFRA من الشهادة', '15', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'ifra_status')).toContainText('فشل');

  await page.getByLabel('وزن الشمع', { exact: true }).fill('');
  await expect(page.getByTestId('missing')).toBeVisible();
  await expect(result(page, 'fragrance_mass')).toHaveCount(0);
});

test('L-06 to L-08: the air-care families are in the registry with what the research states', async ({ page }) => {
  await page.goto('/families/aerosol_air_freshener');
  await expect(page.getByRole('heading', { level: 1 })).toContainText('إيروسول');
  await expect(page.locator('main')).toContainText('خط تعبئة مضغوط');
  await page.goto('/families/gel_air_freshener');
  await expect(page.locator('main')).toContainText('لا توجد فئة واحدة محددة');
  await page.goto('/families?group=air_care');
  await expect(page.locator('main')).toContainText('معطر سيارة');
});
