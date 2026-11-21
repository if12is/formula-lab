import { expect, test } from '@playwright/test';
import { fillFragranceCase, fillNeutralizationCase, fillSoapCase } from './support/calculators';
import {
  CONFIDENCE_COA,
  CONFIDENCE_REFERENCE,
  CONFIDENCE_TDS,
  DEMO_COA,
  DEMO_SOURCE,
  expectNoHorizontalScroll,
  expectRtlAndFits,
  fillDate,
  fillSourced,
  pickOption,
  pickRecord,
  result,
  seedFormulationDemo,
  settle,
  setTheme,
  stamp,
} from './support/kit';

test.beforeAll(() => {
  seedFormulationDemo();
});

test('the index lists every calculator by group and opens each one', async ({ page }) => {
  await page.goto('/calculators');
  await expect(page.getByRole('heading', { level: 1, name: 'الحاسبات' })).toBeVisible();
  await expect(page.locator('[data-calculator]')).toHaveCount(7);
  for (const group of ['الكيمياء والتركيبات', 'التحويل والنسب', 'العطور والمعطرات']) {
    await expect(page.getByRole('heading', { level: 2, name: group })).toBeVisible();
  }
  await page.locator('[data-calculator="hlb"]').click();
  await expect(page).toHaveURL(/\/calculators\/hlb$/);
  await expect(page.getByRole('heading', { level: 1, name: 'HLB' })).toBeVisible();
  // The way back goes to the index, and the sidebar item stays on "Calculators".
  await page.getByRole('link', { name: 'الحاسبات' }).first().click();
  await expect(page).toHaveURL(/\/calculators$/);
});

test('saponification: 500 g at SAP 200, KOH 90 %, superfat 10 % is 100 g, with the water three ways and the SAP spread', async ({ page }) => {
  await page.goto('/calculators/saponification');
  await expect(page.getByTestId('results-card')).toHaveAttribute('data-state', 'empty');

  await fillSoapCase(page);

  await expect(result(page, 'lye_used')).toContainText('100.000');
  await expect(result(page, 'oils_mass')).toContainText('500.000');
  // Ratio 2 given: the other two methods are derived (200 g water; 100 / 300 = 33.333 %; 200 / 500 = 40 %).
  await expect(result(page, 'water_mass')).toContainText('200.000');
  await expect(result(page, 'water_ratio')).toContainText('2.000');
  await expect(result(page, 'water_concentration')).toContainText('33.333');
  await expect(result(page, 'water_pct_of_oils')).toContainText('40.000');
  // SAP 190..210 moves the lye 95..105 g: a spread of 10 g, 10 % of the typical value.
  await expect(result(page, 'lye_min')).toContainText('95.000');
  await expect(result(page, 'lye_max')).toContainText('105.000');
  await expect(result(page, 'spread_abs')).toContainText('10.000');
  await expect(result(page, 'spread_pct')).toContainText('10.000');

  // Another water method with its own value, all three still shown.
  await page.getByRole('button', { name: 'تركيز القلوي %' }).click();
  await page.getByLabel('قيمة طريقة الماء').fill('25');
  await expect(result(page, 'water_mass')).toContainText('300.000');
  await expect(result(page, 'water_pct_of_oils')).toContainText('60.000');
});

test('saponification without a source for the SAP says what is missing and shows no number', async ({ page }) => {
  await page.goto('/calculators/saponification');
  await page.getByLabel('الزيت الزائد (Superfat)').fill('5');
  await page.getByLabel('كتلة الزيت 1', { exact: true }).fill('500');
  const sap = page.getByRole('group', { name: 'رقم التصبن للزيت 1' });
  await sap.getByLabel(/^قيمة/).fill('190');
  await expect(sap.getByTestId('source-required')).toBeVisible();
  const missing = page.getByTestId('missing');
  await expect(missing).toBeVisible();
  await expect(missing).toContainText('رقم التصبن (SAP)');
  await expect(missing).toContainText('النقاوة');
  await expect(missing.getByTestId('missing-item').first()).toContainText('قيمة مطلوبة ناقصة');
  await expect(page.getByTestId('results')).toHaveCount(0);
});

test('sulfonic neutralisation: 1000 g LABSA, acid value 180, NaOH 99 % is 129.615 g, and the pH warning never leaves', async ({ page }) => {
  await page.goto('/calculators/neutralization');
  await expect(page.getByTestId('ph-warning')).toBeVisible();

  await fillNeutralizationCase(page);

  await expect(result(page, 'reagent_mass')).toContainText('129.615');
  await expect(result(page, 'pure_base_mass')).toContainText('128.319');
  await expect(page.getByTestId('ph-warning')).toBeVisible();
  await expect(page.getByTestId('warnings')).toContainText('الأس الهيدروجيني النهائي');

  // A 30 % solution: the base to weigh grows and the water it carries is shown.
  await page.getByRole('button', { name: 'محلول (تركيز)' }).click();
  await expect(page.getByTestId('missing')).toContainText('تركيز المحلول');
  await fillSourced(page, 'تركيز محلول القلوي', '30', DEMO_COA, CONFIDENCE_COA);
  await expect(result(page, 'reagent_mass')).toContainText('427.730');
  await expect(result(page, 'water_in_reagent')).toContainText('299.411');
  await expect(page.getByTestId('ph-warning')).toBeVisible();
});

test('dilution: water for a batch, a raw grade for a target, and a blend with an inactive component', async ({ page }) => {
  await page.goto('/calculators/dilution');
  await expect(page.getByRole('heading', { level: 1, name: 'التخفيف والمادة الفعالة' })).toBeVisible();

  // 1000 g at 15.42 % to 10 %: 1000 x (15.42 / 10 - 1) = 542 g of water.
  await page.getByRole('button', { name: 'ماء لدفعة جاهزة' }).click();
  await page.getByLabel('كتلة الدفعة الحالية', { exact: true }).fill('1000');
  await fillSourced(page, 'المادة الفعالة الحالية', '15.42', DEMO_COA, CONFIDENCE_COA);
  await page.getByLabel('المادة الفعالة المستهدفة', { exact: true }).fill('10');
  await expect(result(page, 'water_mass')).toContainText('542.000');
  await expect(result(page, 'final_mass')).toContainText('1,542.000');
  // A target above the current active cannot be reached by adding water.
  await page.getByLabel('المادة الفعالة المستهدفة', { exact: true }).fill('20');
  await expect(page.getByTestId('missing')).toContainText('لا يمكن بلوغها بإضافة ماء');

  // 70 % raw grade, 10 % target, 1000 g: 1000 x 10 / 70 = 142.857 g raw and 857.143 g water.
  await page.getByRole('button', { name: 'خام وماء لنسبة مستهدفة' }).click();
  await fillSourced(page, 'المادة الفعالة في الخام', '70', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByLabel('المادة الفعالة المستهدفة', { exact: true }).fill('10');
  await page.getByLabel('كتلة الدفعة النهائية', { exact: true }).fill('1000');
  await expect(result(page, 'raw_mass')).toContainText('142.857');
  await expect(result(page, 'diluent_mass')).toContainText('857.143');

  // A blend: 100 g at 70 % and 100 g of water (inactive, 0 %) is 35 %.
  await page.getByRole('button', { name: 'نسبة المادة الفعالة في خليط' }).click();
  await page.getByLabel('كتلة المكوّن 1', { exact: true }).fill('100');
  await fillSourced(page, 'المادة الفعالة في المكوّن 1', '70', DEMO_SOURCE, CONFIDENCE_TDS);
  await page.getByRole('button', { name: 'إضافة مكوّن' }).click();
  await page.getByLabel('كتلة المكوّن 2', { exact: true }).fill('100');
  await page.getByRole('switch', { name: 'المكوّن 2 يحمل مادة فعالة' }).click();
  await fillSourced(page, 'المادة الفعالة في المكوّن 2', '0', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'active_pct')).toContainText('35.000');
  await expect(result(page, 'active_mass')).toContainText('70.000');
  await expect(result(page, 'total_mass')).toContainText('200.000');
});

test('HLB: the required HLB of an oil phase is 8, a blend splits two emulsifiers, Griffin and Davies compute', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await expect(page.getByRole('heading', { level: 1, name: 'HLB' })).toBeVisible();

  // 50 g at 6 and 50 g at 10: (50 x 6 + 50 x 10) / 100 = 8.
  await page.getByLabel('كتلة المكوّن 1', { exact: true }).fill('50');
  await fillSourced(page, 'HLB المطلوب للمكوّن 1', '6', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByRole('button', { name: 'إضافة مكوّن زيتي' }).click();
  await page.getByLabel('كتلة المكوّن 2', { exact: true }).fill('50');
  await fillSourced(page, 'HLB المطلوب للمكوّن 2', '10', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await expect(result(page, 'required_hlb')).toContainText('8.00');

  // Target 8 from emulsifiers of HLB 4 and 12, 100 g in all: half and half.
  await page.getByRole('button', { name: 'مزج مستحلبين' }).click();
  await page.getByLabel('HLB المستهدف', { exact: true }).fill('8');
  await fillSourced(page, 'HLB للمستحلب المنخفض', '4', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await fillSourced(page, 'HLB للمستحلب المرتفع', '12', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByLabel('كتلة المستحلبين معاً', { exact: true }).fill('100');
  await expect(result(page, 'mass_high')).toContainText('50.000');
  await expect(result(page, 'mass_low')).toContainText('50.000');

  // Griffin: 20 x 60 / 100 = 12.
  await page.getByRole('button', { name: 'Griffin' }).click();
  await page.getByLabel('كتلة الجزء المحب للماء (Mh)').fill('60');
  await page.getByLabel('الكتلة الجزيئية الكلية (M)').fill('100');
  await expect(result(page, 'griffin')).toContainText('12.00');

  // Davies: 7 + 2 x 1.9 - 0.475 x 2 = 9.85 for two groups of number 1.9 (inputs, not data).
  await page.getByRole('button', { name: 'Davies' }).click();
  await page.getByLabel('عدد مرات المجموعة 1', { exact: true }).fill('2');
  await fillSourced(page, 'رقم Davies للمجموعة 1', '1.9', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await expect(result(page, 'davies')).toContainText('9.85');
});

test('litre and kilo: no density means missing, never a number', async ({ page }) => {
  await page.goto('/calculators/litre-kilo');
  await page.getByLabel('الوزن', { exact: true }).fill('1');

  const missing = page.getByTestId('missing');
  await expect(missing).toBeVisible();
  await expect(missing).toContainText('الكثافة');
  await expect(missing).toContainText('يحتاج إلى كثافة');
  await expect(page.getByTestId('results')).toHaveCount(0);

  // 1 kg at 0.9 g/mL is 1.111 L; and back, 2 L at 0.9 g/mL is 1.8 kg.
  await fillSourced(page, 'الكثافة المقاسة', '0.9', DEMO_COA, CONFIDENCE_COA);
  await expect(result(page, 'volume')).toContainText('1.111');
  await page.getByRole('button', { name: 'من حجم إلى وزن' }).click();
  await page.getByLabel('الحجم', { exact: true }).fill('2');
  await expect(result(page, 'mass')).toContainText('1.800');
});

test('percent from weight: 25 g in 1 kg is 2.5 %, and a volume batch asks for its density', async ({ page }) => {
  await page.goto('/calculators/percent-from-weight');
  await page.getByLabel('كمية الخامة', { exact: true }).fill('25');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1');
  await expect(result(page, 'percent')).toContainText('2.500');

  await pickOption(page, page.getByRole('combobox', { name: /وحدة حجم الدفعة/ }), 'لتر');
  await expect(page.getByTestId('missing')).toContainText('كثافة المنتج');
  await fillSourced(page, 'كثافة المنتج المقاسة', '2', DEMO_COA, CONFIDENCE_COA);
  await expect(result(page, 'percent')).toContainText('1.250');
});

test('fragrance (S-14): the batch masses, the indicator warning, the PASS / FAIL / UNKNOWN IFRA verdict', async ({ page }) => {
  await fillFragranceCase(page);

  // 1000 g, 20 % oil, 1 % fixative: carrier 790 g = 395 g stock + 395 g water, oil 200 g, fixative 10 g.
  await expect(result(page, 'oil_mass')).toContainText('200.000');
  await expect(result(page, 'fixative_mass')).toContainText('10.000');
  await expect(result(page, 'alcohol_mass')).toContainText('395.000');
  await expect(result(page, 'water_mass')).toContainText('395.000');
  await expect(result(page, 'ethanol_ww')).toContainText('31.600');
  // The final strength by volume is an indicator and says so, twice: on the row and in the notes and warnings.
  await expect(result(page, 'ethanol_vv')).toContainText('38.08');
  await expect(result(page, 'ethanol_vv')).toContainText('مؤشر فقط');
  await expect(page.getByTestId('indicator-note')).toBeVisible();
  await expect(page.getByTestId('warnings')).toContainText('مؤشر فقط');

  // The limit on the certificate is 25 %: 20 % passes with a margin of 5.
  await expect(result(page, 'ifra_status')).toContainText('نجح');
  await expect(result(page, 'ifra_margin')).toContainText('5.000');
  // 30 % fails.
  await page.getByLabel('زيت العطر', { exact: true }).fill('30');
  await expect(result(page, 'ifra_status')).toContainText('فشل');
  await expect(result(page, 'ifra_margin')).toContainText('-5.000');
});

test('fragrance: with no IFRA limit the verdict is unknown, never a pass', async ({ page }) => {
  await page.goto('/calculators/fragrance');
  await page.getByRole('button', { name: 'فحص IFRA' }).click();
  await page.getByLabel('زيت العطر', { exact: true }).fill('20');
  await expect(result(page, 'ifra_status')).toContainText('غير معروف');
  await expect(result(page, 'ifra_status')).not.toContainText('نجح');
  await expect(page.getByTestId('warnings')).toContainText('وهذا ليس نجاحاً');

  await fillSourced(page, 'حد IFRA من الشهادة', '25', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'ifra_status')).toContainText('نجح');
});

test('alcohol dilution: 80 %w/w stock to the OIML 40.0 row is 500 g + 500 g, 1069.35 mL, and the contraction is shown', async ({ page }) => {
  await page.goto('/calculators/fragrance');
  await page.getByRole('button', { name: 'تخفيف الكحول' }).click();
  await fillSourced(page, 'تركيز الكحول المتاح', '80', DEMO_COA, CONFIDENCE_COA);
  const stock = page.getByRole('group', { name: 'تركيز الكحول المتاح' });
  await pickOption(page, stock.getByRole('combobox', { name: /^وحدة/ }), '% وزناً');
  await page.getByLabel('تركيز الكحول المستهدف', { exact: true }).fill('47.3947631607');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1000');

  await expect(result(page, 'source_mass')).toContainText('500.000');
  await expect(result(page, 'water_mass')).toContainText('500.000');
  await expect(result(page, 'total_volume')).toContainText('1,069.35');
  await expect(result(page, 'source_volume')).toContainText('592.85');
  await expect(result(page, 'water_volume')).toContainText('500.90');
  await expect(result(page, 'contraction_volume')).toContainText('24.40');
  await expect(result(page, 'contraction_pct')).toContainText('2.23');

  // The same batch by volume: 1 L of the 40 %w/w mixture weighs 935.145 g.
  await page.getByRole('button', { name: 'بالحجم' }).click();
  await pickOption(page, page.getByRole('combobox', { name: /وحدة حجم الدفعة/ }), 'لتر');
  await page.getByLabel('حجم الدفعة', { exact: true }).fill('1');
  await expect(result(page, 'total_mass')).toContainText('935.145');
  await expect(result(page, 'source_mass')).toContainText('467.573');
});

test('a material prefills a sourced input through the engine: SAP from the material, with its source and confidence', async ({ page }) => {
  const name = `Spec Oil ${stamp()}`;

  // The property form offers the registry (names in words, units that belong to the property), not free text.
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const materialDialog = page.getByRole('dialog');
  await materialDialog.getByLabel('الاسم التجاري').fill(name);
  await materialDialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);

  await page.getByRole('button', { name: 'إضافة قيمة خاصية' }).first().click();
  const property = page.getByRole('dialog');
  await pickRecord(page, property.getByRole('combobox', { name: /^الخاصية/ }), 'التصبن', /رقم التصبن \(SAP\)/);
  await expect(property.getByRole('combobox', { name: /^الوحدة/ })).toContainText('mgKOH/g');
  await property.getByLabel(/^القيمة/).fill('188.5');
  await pickRecord(page, property.getByRole('combobox', { name: /المصدر/ }), DEMO_SOURCE);
  await pickOption(page, property.getByRole('combobox', { name: /درجة الثقة/ }), CONFIDENCE_TDS);
  await fillDate(property, /سارية من/, '2026-03-01');
  await property.getByRole('button', { name: 'إضافة القيمة' }).click();
  // The card is titled by the property's name, not its key.
  await expect(page.locator('[data-property="sap"]')).toContainText('رقم التصبن (SAP)');
  await expect(page.locator('[data-property="sap"]')).not.toContainText('sap_range');

  await page.goto('/calculators/saponification');
  await pickRecord(page, page.getByRole('combobox', { name: 'الزيت 1', exact: true }), name);
  const sap = page.getByRole('group', { name: 'رقم التصبن للزيت 1' });
  await expect(sap.getByLabel(/^قيمة/)).toHaveValue('188.5');
  await expect(sap.getByRole('combobox', { name: /^مصدر/ })).toHaveValue(DEMO_SOURCE);
  await expect(sap).toContainText(`من الخامة: ${name}`);
  await expect(sap).toContainText('2026-03-01');

  await page.getByLabel('كتلة الزيت 1', { exact: true }).fill('1000');
  await page.getByLabel('الزيت الزائد (Superfat)').fill('5');
  await fillSourced(page, 'نقاوة القلوي', '97', DEMO_COA, CONFIDENCE_COA);
  // NaOH is the default: 1000 g x 188.5 mgKOH/g x f x 0.95 / 0.97 (the engine's own value, shown to 3 places).
  await expect(result(page, 'lye_used')).toBeVisible();
  await expect(page.getByTestId('results')).toBeVisible();
});

test('the explain panel opens from a result and shows the calculation with a source and confidence on each input', async ({ page }) => {
  await fillSoapCase(page);

  await result(page, 'lye_used').getByTestId('explain-trigger').click();
  const panel = page.getByTestId('explain-panel');
  await expect(panel).toBeVisible();
  await expect(page.getByRole('dialog')).toContainText('كيف حُسب هذا الرقم؟');
  // The root is the engine's formula; the leaves are the inputs.
  const tree = panel.getByTestId('trace-tree');
  await expect(tree).toContainText('Lye (used)');
  await expect(tree.locator('[data-trace-leaf]').first()).toBeVisible();
  // The SAP leaf: its source by name and its confidence badge.
  const sapLeaf = tree.locator('[data-trace-leaf]').filter({ hasText: 'SAP typ' }).first();
  await expect(sapLeaf).toContainText(DEMO_SOURCE);
  await expect(sapLeaf).toContainText('L2');
  // The purity leaf comes from the lot certificate: L1.
  const purityLeaf = tree.locator('[data-trace-leaf]').filter({ hasText: 'purity' }).first();
  await expect(purityLeaf).toContainText(DEMO_COA);
  await expect(purityLeaf).toContainText('L1');
  // A typed requirement (the superfat) is plainly the user's own.
  await expect(tree.locator('[data-trace-leaf]').filter({ hasText: 'Superfat' }).first()).toContainText('مدخل المستخدم');

  await page.keyboard.press('Escape');
  await expect(panel).toHaveCount(0);
});

test('an estimate (L7) leaf is flagged in the explain panel and the engine refuses to compute with it', async ({ page }) => {
  await page.goto('/calculators/neutralization');
  await page.getByLabel('كتلة الحمض', { exact: true }).fill('1000');
  await fillSourced(page, 'رقم الحموضة', '180', DEMO_COA, 'تقدير');
  await fillSourced(page, 'نقاوة القلوي الصلب', '99', DEMO_COA, CONFIDENCE_COA);
  // L7 without a signed override is missing data: no number is shown.
  await expect(page.getByTestId('missing')).toContainText('رقم الحموضة');
  await expect(page.getByTestId('missing')).toContainText('سعر تقديري');
});

test('the formula editor explains its cost and its sum from the engine trace', async ({ page }) => {
  await page.goto('/formulas?search=DEMO-SHAMPOO');
  await page.getByText('DEMO-SHAMPOO').first().click();
  await expect(page).toHaveURL(/\/versions\/3\/edit/);
  await expect(page.getByTestId('line')).toHaveCount(4);

  // The sum: its leaves are the typed percentages (the q.s. line has none yet, so three of the four lines).
  await page.getByTestId('sum').getByTestId('explain-trigger').click();
  const sumPanel = page.getByTestId('explain-panel');
  await expect(sumPanel).toBeVisible();
  await expect(sumPanel.getByTestId('trace-tree')).toContainText('Σ % w/w');
  await expect(sumPanel.getByTestId('trace-tree').locator('[data-trace-leaf]')).toHaveCount(3);
  await page.keyboard.press('Escape');
  await expect(sumPanel).toHaveCount(0);

  // The cost per kg is either explainable (every price known) or says why it is not; the demo fragrance is an L7 estimate.
  const cost = page.getByTestId('cost-per-kg');
  await expect(cost).toBeVisible();
  if (await cost.getByTestId('explain-trigger').count()) {
    await cost.getByTestId('explain-trigger').click();
    await expect(page.getByTestId('explain-panel').getByTestId('trace-source').first()).toBeVisible();
  } else {
    await expect(cost).toContainText('—');
  }
});

test('every calculator fits a phone and an RTL page, light and dark', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const path of ['', '/saponification', '/neutralization', '/dilution', '/hlb', '/litre-kilo', '/percent-from-weight', '/fragrance']) {
    await page.goto(`/calculators${path}`);
    await expect(page.locator('h1')).toBeVisible();
    await settle(page);
    await expectRtlAndFits(page);
  }

  await fillSoapCase(page);
  await expectNoHorizontalScroll(page);
  // The explain panel is a bottom sheet on a phone and does not overflow either.
  await result(page, 'lye_used').getByTestId('explain-trigger').click();
  await expect(page.getByTestId('explain-panel')).toBeVisible();
  const box = await page.getByRole('dialog').boundingBox();
  expect(box).not.toBeNull();
  expect(box!.y).toBeGreaterThan(40);
  expect(box!.width).toBeLessThanOrEqual(390);
  await expectNoHorizontalScroll(page);
  await page.keyboard.press('Escape');

  await setTheme(page, 'dark');
  await expectNoHorizontalScroll(page);
  await setTheme(page, 'light');
});

test('the sidebar item Calculators opens the index instead of a placeholder', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'الحاسبات' }).first().click();
  await expect(page).toHaveURL(/\/calculators$/);
  await expect(page.getByRole('heading', { level: 1, name: 'الحاسبات' })).toBeVisible();
  await expect(page.getByText('هذه الشاشة قيد التنفيذ')).toHaveCount(0);
});
