import { execSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';
import { CONFIDENCE_COA, CONFIDENCE_TDS, DEMO_COA, DEMO_SOURCE, fillSourced, pickOption, pickRecord, result } from './kit';

/** Seeds the demo fragrance oil (IFRA certificate, one limit, no density / flash point / price) and the DEMO-PERFUME formula with lab losses. Idempotent. */
export function seedFragranceDemo(): void {
  execSync('php artisan db:seed --class=FragranceDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
}

/**
 * The hand-checked perfume of the engine's perfume-cost test (TEST inputs): 1 kg of product = oil 20 %, fixative 1 %, carrier 79 %
 * from 80 %w/w stock at the OIML 40.0 row (stock ethanol 39.5 %, water 39.5 %), prices EGP/kg oil 5000, alcohol 100, water 2,
 * fixative 1000 => 1050.29 EGP per kg; a 100 mL bottle at density 0.9 g/mL is 0.09 kg => 94.5261; bottle 12 + sprayer 3 + box 2.
 * With no loss the direct cost is 111.5261; with the demo lab losses (2 %, 1 %, 3 %) the yield is 0.941094.
 */
export async function fillPerfumeCostCase(page: Page, losses: 'typed' | 'lab' = 'typed'): Promise<void> {
  await page.goto('/calculators/fragrance');
  await expect(page.getByRole('heading', { level: 1, name: 'العطور والمعطرات' })).toBeVisible();
  await page.getByRole('button', { name: 'تكلفة العطر' }).click();
  await page.getByLabel('زيت العطر', { exact: true }).fill('20');
  await page.getByLabel('المثبت', { exact: true }).fill('1');
  await fillSourced(page, 'تركيز الكحول المتاح', '80', DEMO_COA, CONFIDENCE_COA);
  await pickOption(page, page.getByRole('group', { name: 'تركيز الكحول المتاح' }).getByRole('combobox', { name: /^وحدة/ }), '% وزناً');
  await page.getByLabel('تركيز الكحول المستهدف', { exact: true }).fill('47.3947631607');
  await fillSourced(page, 'كثافة المنتج المقاسة', '0.9', DEMO_COA, CONFIDENCE_COA);

  for (const [legend, value] of [['سعر زيت العطر', '5000'], ['سعر الكحول', '100'], ['سعر الماء', '2'], ['سعر المثبت', '1000']] as const) {
    await fillSourced(page, legend, value, DEMO_SOURCE, CONFIDENCE_TDS);
  }
  if (losses === 'typed') {
    for (const legend of ['فاقد التعتيق', 'فاقد التبريد', 'فاقد الفلترة']) {
      await fillSourced(page, legend, '0', DEMO_SOURCE, CONFIDENCE_TDS);
    }
  } else {
    await pickRecord(page, page.getByRole('combobox', { name: /^خذ الفاقد من نسخة تركيبة/ }), 'DEMO-PERFUME', /DEMO-PERFUME/);
    await expect(page.getByTestId('lab-loss-used')).toBeVisible();
  }
  await fillSourced(page, 'التعبئة في الزجاجة', '100', DEMO_SOURCE, CONFIDENCE_TDS);
  for (const [legend, value] of [['سعر الزجاجة', '12'], ['سعر البخاخ', '3'], ['سعر العلبة', '2']] as const) {
    await fillSourced(page, legend, value, DEMO_SOURCE, CONFIDENCE_TDS);
  }
  for (const legend of ['نسبة هالك الزجاجة', 'نسبة هالك البخاخ', 'نسبة هالك العلبة']) {
    await fillSourced(page, legend, '0', DEMO_SOURCE, CONFIDENCE_TDS);
  }
}

/** Room spray (hand case 1 of the engine test): 1000 g, oil 2 %, ratio 3:1 from a trial, other 0.5 % => solubilizer 60 g, water 915 g. */
export async function fillRoomSprayCase(page: Page): Promise<void> {
  await page.goto('/calculators/fragrance');
  await expect(page.getByRole('heading', { level: 1, name: 'العطور والمعطرات' })).toBeVisible();
  await page.getByRole('button', { name: 'معطر رشاش' }).click();
  await page.getByLabel('زيت العطر', { exact: true }).fill('2');
  await page.getByLabel('إضافات أخرى', { exact: true }).fill('0.5');
  await fillSourced(page, 'نسبة المذيب إلى العطر', '3', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByRole('button', { name: 'تجربة موثقة' }).click();
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1000');
  await expect(result(page, 'solubilizer_mass')).toContainText('60.000');
}
