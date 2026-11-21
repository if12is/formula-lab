import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_TDS, DEMO_SOURCE, expectNoHorizontalScroll, fillSourced, pickOption, pickRecord, result, settle } from './support/kit';
import { seedLabDemo } from './support/lab';

/**
 * The lab loop, second half (O-03, O-04, O-05, O-07). Hand-checked figures (the seeder's placeholder demo data):
 *   salt curve   DEMO-LAB-CURVE: 0 % salt -> 120 mPa.s, 1 % -> 1150; the spec adds 2 % -> 3150.
 *                at 0.5 %: 120 + 0.5 x (1150 - 120) = 635;  at 1.5 %: 1150 + 0.5 x (3150 - 1150) = 2150;  for 635 mPa.s: 0.5 % salt
 *   density      DEMO-LAB-APPROVED measured 1.03 g/mL: 2.06 kg / 1.03 = 2 L (an estimate of 2 g/mL typed beside it is NOT used)
 *   comparison   expected active = (12 x 70 + 88 x 0) / 100 = 8.4 ; measured 8.1 ; difference -0.3 ; relative -0.3 / 8.4 x 100 = -3.57 %
 *   tested on    DEMO-LAB-TESTED: dose 12, q.s. 88 and the required HLB 11 of "Demo Lab Surfactant" are recorded after its stability result
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedLabDemo();
});

async function openTrial(page: Page, code: string): Promise<void> {
  await page.goto(`/lab/trials?search=${encodeURIComponent(code)}`);
  await page.getByText(code, { exact: true }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: code })).toBeVisible();
}

test('O-03: a salt level is recorded with a viscosity reading, joins the formula curve, and the engine reads the curve', async ({ page }) => {
  await openTrial(page, 'DEMO-LAB-CURVE/T2');
  const form = page.getByTestId('measurement-form');

  // The salt box belongs to the viscosity reading only.
  await expect(form.getByLabel(/^الملح المضاف/)).toHaveCount(0);
  await pickOption(page, form.getByTestId('measurement-property'), 'اللزوجة');
  await form.getByLabel(/^القيمة/).fill('3150');
  await form.getByLabel(/^الملح المضاف/).fill('2');
  await form.getByLabel(/^الطريقة/).fill('Demo bench method');
  await form.getByLabel(/^الجهاز المستخدم/).fill('Demo Brookfield 2');
  await form.getByTestId('measurement-submit').click();
  await expect(page.getByTestId('salt-readings')).toContainText('3150');

  // A salt reading is not the formula's own viscosity: that row stays "not measured".
  await expect(page.locator('[data-value="viscosity"]')).toContainText('لم يُقَس');

  await page.getByTestId('salt-curve-link').click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('DEMO-LAB-CURVE');
  await expect(page.getByTestId('salt-curve')).toHaveAttribute('data-points', '3');
  await expect(page.locator('[data-salt-point]')).toHaveCount(3);
  await expect(page.getByTestId('salt-range')).toContainText('من 0 إلى 2');

  const query = page.getByTestId('salt-query').getByRole('textbox');
  await query.fill('0.5');
  await expect(result(page, 'viscosity')).toContainText('635.0');
  await query.fill('1.5');
  await expect(result(page, 'viscosity')).toContainText(/2,?150\.0/);
  // The explain panel names the two measured neighbours as its sources.
  await result(page, 'viscosity').getByTestId('explain-trigger').click();
  await expect(page.getByTestId('trace-tree').locator('[data-trace-leaf]').filter({ hasText: 'DEMO-LAB-CURVE/T' }).first()).toBeVisible();
  await page.keyboard.press('Escape');

  // Outside the measured range the engine does not extrapolate.
  await query.fill('2.5');
  await expect(page.getByTestId('salt-result').getByTestId('missing')).toContainText('خارج مدى القياسات');
  await expect(page.locator('[data-result="viscosity"]')).toHaveCount(0);

  // The other way round: 635 mPa.s needs 0.5 % salt.
  await page.getByRole('button', { name: 'نسبة الملح لبلوغ لزوجة' }).click();
  await query.fill('635');
  await expect(result(page, 'salt_0')).toContainText('0.500');
});

test('O-03: a formula without readings has no curve, and the engine says so instead of answering', async ({ page }) => {
  await openTrial(page, 'DEMO-SHAMPOO/T1');
  await page.getByTestId('salt-curve-link').click();
  await expect(page.getByTestId('salt-empty')).toBeVisible();
  await page.getByTestId('salt-query').getByRole('textbox').fill('1');
  await expect(page.getByTestId('salt-result').getByTestId('missing')).toContainText('لا يوجد منحنى ملح لهذه التركيبة');
  await expectNoHorizontalScroll(page);
});

test('O-04: the density measured for a version is used in litre/kilo in preference to a typed estimate, with its trial as source', async ({ page }) => {
  await openTrial(page, 'DEMO-LAB-APPROVED/T1');
  await expect(page.getByTestId('measured-density-value')).toContainText('1.03 g/mL');

  await page.goto('/calculators/litre-kilo');
  await page.getByLabel('الوزن', { exact: true }).fill('2.06');
  await pickRecord(page, page.getByRole('combobox', { name: /^نسخة التركيبة/ }), 'DEMO-LAB-APPROVED', /DEMO-LAB-APPROVED/);
  await expect(page.getByTestId('lab-density-used')).toContainText('1.03 g/mL');
  await expect(result(page, 'volume')).toContainText('2.000');

  // A typed estimate of 2 g/mL does not replace the measured one.
  await fillSourced(page, 'الكثافة المقاسة', '2', DEMO_SOURCE, CONFIDENCE_TDS);
  await expect(result(page, 'volume')).toContainText('2.000');

  // The trace holds the lab reading as the density's source, with its confidence.
  await result(page, 'volume').getByTestId('explain-trigger').click();
  const leaf = page.getByTestId('trace-tree').locator('[data-trace-leaf]').filter({ hasText: 'Measured product density (lab)' }).first();
  await expect(leaf).toContainText('L1');
  await expect(leaf).toContainText('قياس التجربة');
  await page.keyboard.press('Escape');

  // A version nobody measured the density of says so, and the typed (estimated) density is used.
  await pickRecord(page, page.getByRole('combobox', { name: /^نسخة التركيبة/ }), 'DEMO-LAB-BLOCKED', /DEMO-LAB-BLOCKED/);
  await expect(page.getByTestId('lab-density-none')).toBeVisible();
  await expect(page.getByTestId('lab-density-used')).toHaveCount(0);
  await expect(result(page, 'volume')).toContainText('1.030'); // 2.06 kg / 2 g/mL
});

test('O-05: the computed active matter against the measured one, as absolute and relative difference and no verdict', async ({ page }) => {
  await openTrial(page, 'DEMO-LAB-CURVE/T2');
  const panel = page.getByTestId('comparison');
  await expect(panel).toHaveAttribute('data-state', 'ready');
  await expect(result(page, 'expected')).toContainText('8.40');
  await expect(result(page, 'measured')).toContainText('8.10');
  await expect(result(page, 'difference')).toContainText('-0.30');
  await expect(result(page, 'relative')).toContainText('-3.57');
  await expect(page.getByTestId('comparison-no-verdict')).toContainText('لا حد قبول معرّف');
  await expect(panel).not.toContainText(/قبول\b.*مطابق|مرفوض|ناجح|راسب/);

  // The expected figure explains itself from the engine's tree: each material's active matter with its source.
  await result(page, 'expected').getByTestId('explain-trigger').click();
  await expect(page.getByTestId('trace-tree')).toContainText('Demo Lab Surfactant');
  await page.keyboard.press('Escape');

  // A trial without an active matter reading has nothing to compare: it says so, with no figure.
  await openTrial(page, 'DEMO-LAB-CURVE/T1');
  await expect(page.getByTestId('comparison-no-measured')).toBeVisible();
  await expect(page.getByTestId('comparison-rows')).toHaveCount(0);

  // A formula whose materials have no registered active matter: the engine lists what is missing, nothing is guessed.
  await openTrial(page, 'DEMO-SHAMPOO/T2');
  await expect(page.getByTestId('comparison').getByTestId('missing')).toBeVisible();
  await expect(page.getByTestId('comparison-rows')).toHaveCount(0);
});

test('O-07: a stability result on an approved version marks the tested dose and required HLB, and the HLB calculator tags the same value only', async ({ page }) => {
  await openTrial(page, 'DEMO-LAB-TESTED/T1');
  const tested = page.getByTestId('tested-values');
  await expect(tested.locator('[data-tested="dose:12"]')).toBeVisible();
  await expect(tested.locator('[data-tested="dose:88"]')).toBeVisible();
  await expect(tested.locator('[data-tested="required_hlb:11"]')).toBeVisible();

  await page.goto('/calculators/hlb');
  await pickRecord(page, page.getByRole('combobox', { name: 'المكوّن الزيتي 1' }), 'Demo Lab Surf', /Demo Lab Surfactant/);
  const group = page.getByRole('group', { name: 'HLB المطلوب للمكوّن 1' });
  await expect(group.getByLabel(/^قيمة/)).toHaveValue('11');
  const tag = page.getByTestId('tested-on');
  await expect(tag).toContainText('جُرّبت على تركيبة DEMO-LAB-TESTED نسخة 1');

  // Only that value carries the record: change the number and the tag goes, the sum is untouched by it.
  await group.getByLabel(/^قيمة/).fill('12');
  await expect(tag).toHaveCount(0);
  await group.getByLabel(/^قيمة/).fill('11');
  await expect(tag).toBeVisible();
});

test('the new screens fit a phone in Arabic RTL', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const url of ['/lab/trials?search=DEMO-LAB-CURVE', '/calculators/litre-kilo', '/calculators/hlb']) {
    await page.goto(url);
    await settle(page);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expectNoHorizontalScroll(page);
  }
});
