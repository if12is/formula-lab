import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { CONFIDENCE_REFERENCE, DEMO_SOURCE, expectNoHorizontalScroll, fillSourced, pickOption, pickRecord, result, seedFormulationDemo, settle, stamp } from './support/kit';

/**
 * M-06 (skincare templates), M-07 (stability protocols), M-08 (ionic paths, rHLB confidence filter, L7 refused),
 * M-09 (skincare presets) and H-02 (closure compatibility). Hand-computed figures:
 *   cationic dose 6.25 against a TDS range 2..5            => above the range by 1.25
 *   cationic dose 3.5 against 2..5                          => inside (distance 0)
 *   anionic 120 g at 28% active in a 1000 g formula         => 33.6 g active = 3.36%
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedFormulationDemo();
  execSync('php artisan db:seed --class=FamilyDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

const suffix = stamp();

test('a material carries its ionic character and the library filters by it, and by the confidence of the required HLB', async ({ page }) => {
  const name = `Spec Quat ${suffix}`;
  await page.goto('/materials');
  await page.getByRole('button', { name: 'خامة جديدة' }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('الاسم التجاري').fill(name);
  await pickOption(page, dialog.getByTestId('ionicity'), 'كاتيوني');
  await dialog.getByRole('button', { name: 'إضافة الخامة' }).click();
  await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
  await expect(page.getByText('كاتيوني (موجب)').first()).toBeVisible();

  // Give it an L7 required HLB, so the list shows its confidence.
  const id = page.url().split('/materials/')[1]!.split(/[?#]/)[0]!;
  execSync(
    `php artisan tinker --execute='$s = App\\Models\\Source::query()->firstOrFail(); App\\Models\\PropertyValue::query()->create(["entity_type" => "trade_material", "entity_id" => "${id}", "property" => "required_hlb", "value" => "10", "unit" => "HLB", "source_id" => $s->id, "confidence" => "L7_estimate", "valid_from" => "2026-01-01"]);'`,
    { cwd: 'apps/api', stdio: 'pipe' },
  );

  await page.goto(`/materials?search=${encodeURIComponent(name)}`);
  const row = page.getByRole('row', { name: new RegExp(name) });
  await expect(row).toBeVisible();
  await expect(row).toContainText('كاتيوني (موجب)');
  await expect(row).toContainText('L7');

  // The two filters write their own address parameters and keep the material.
  await page.locator('[data-filter-controls]').getByRole('button', { name: 'الطبيعة الأيونية' }).click();
  await page.getByRole('menuitemradio', { name: 'كاتيوني (موجب)' }).click();
  await expect(page).toHaveURL(/ionicity=cationic/);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();
  await page.locator('[data-filter-controls]').getByRole('button', { name: 'ثقة HLB المطلوب (الأسوأ)' }).click();
  await page.getByRole('menuitemradio', { name: /تقدير/ }).click();
  await expect(page).toHaveURL(/required_hlb_confidence=L7_estimate/);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toBeVisible();

  await page.goto(`/materials?search=${encodeURIComponent(name)}&ionicity=anionic`);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toHaveCount(0);
  await page.goto(`/materials?search=${encodeURIComponent(name)}&required_hlb_confidence=L1_coa_batch`);
  await expect(page.getByRole('row', { name: new RegExp(name) })).toHaveCount(0);
});

test('HLB: a cationic emulsifier is compared with its TDS range (no HLB), and an L7 range is refused', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await page.getByRole('button', { name: 'مستحلب كاتيوني (نطاق TDS)' }).click();

  await page.getByLabel('الجرعة المستخدمة', { exact: true }).fill('6.25');
  await fillSourced(page, 'نطاق TDS: الحد الأدنى', '2', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await fillSourced(page, 'نطاق TDS: الحد الأقصى', '5', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await expect(result(page, 'tds_above')).toContainText('1.250');

  await page.getByLabel('الجرعة المستخدمة', { exact: true }).fill('3.5');
  await expect(result(page, 'tds_within')).toContainText('0.000');
  await expect(result(page, 'tds_above')).toHaveCount(0);

  // The same range typed as an estimate (L7) is not used in the final calculation.
  await pickOption(page, page.getByRole('group', { name: 'نطاق TDS: الحد الأدنى' }).getByRole('combobox', { name: /^ثقة/ }), /تقدير/);
  await expect(page.getByTestId('missing')).toContainText(/تقدير \(L7\) فقط/);
  await expect(result(page, 'tds_within')).toHaveCount(0);
});

test('HLB: an anionic surfactant is worked out from its active matter: 120 g at 28% in 1000 g = 33.6 g = 3.36%', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await page.getByRole('button', { name: 'سطحي أنيوني (مادة فعالة)' }).click();
  await page.getByLabel('كتلة الخامة', { exact: true }).fill('120');
  await fillSourced(page, 'المادة الفعالة في الخامة', '28', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await expect(result(page, 'active_mass')).toContainText('33.600');
  await page.getByLabel('الكتلة الكلية للتركيبة (اختياري)', { exact: true }).fill('1000');
  await expect(result(page, 'active_percent')).toContainText('3.360');
});

test('HLB: an L7 emulsifier HLB is refused in the blend, like an L7 required HLB', async ({ page }) => {
  await page.goto('/calculators/hlb');
  await page.getByRole('button', { name: 'مزج مستحلبين' }).click();
  await page.getByLabel('HLB المستهدف', { exact: true }).fill('10.0');
  await fillSourced(page, 'HLB للمستحلب المنخفض', '4.3', DEMO_SOURCE, /تقدير/);
  await fillSourced(page, 'HLB للمستحلب المرتفع', '15.0', DEMO_SOURCE, CONFIDENCE_REFERENCE);
  await page.getByLabel('كتلة المستحلبين معاً', { exact: true }).fill('107');
  await expect(page.getByTestId('missing')).toContainText(/تقدير \(L7\) فقط/);
  await expect(result(page, 'mass_high')).toHaveCount(0);
});

test('stability protocols are lab protocol templates with no fixed setting except 4 weeks at 40 °C', async ({ page }) => {
  await page.goto('/lab/protocols?search=stability');
  await settle(page);
  const rows = page.locator('tbody tr');
  await expect(rows).toHaveCount(3);

  await page.goto('/lab/protocols?search=stability_40c');
  await rows.first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'الثبات لمدة 4 أسابيع عند 40 °C' })).toBeVisible();
  await expect(page.getByTestId('protocol-steps').locator('li')).toHaveCount(5);
  await expect(page.getByTestId('protocol-steps')).toContainText('40 °C');
  await expect(page.getByText('لا يوجد حد معرّف')).toBeVisible();

  await page.goto('/lab/protocols?search=stability_centrifuge');
  await rows.first().click();
  // The steps name what to do; the only digit on each is its own step number.
  await expect(page.getByTestId('protocol-steps').locator('li')).toHaveCount(5);
  const steps = await page.getByTestId('protocol-steps').locator('li').allInnerTexts();
  for (const step of steps) {
    expect(step.replace(/^\s*[0-9]+\s*/, '')).not.toMatch(/[0-9٠-٩]/);
  }
  await expectNoHorizontalScroll(page);
});

test('skincare presets and templates: eleven types, IFRA only where the research states it, six structure-only templates', async ({ page }) => {
  await page.goto('/families?group=skincare&per=100');
  await settle(page);
  await expect(page.locator('tbody tr')).toHaveCount(11);
  await page.goto('/families?ifra_category=5B');
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr').first()).toContainText('كريم وش');

  await page.goto('/families/hand_cream');
  await expect(page.getByTestId('family-facts')).toContainText('EDA');
  await expect(page.getByTestId('family-facts')).toContainText('غير محدد لحد دلوقتي');

  await page.goto('/families/sunscreen');
  await expect(page.getByTestId('family-facts')).toContainText('غير محدد لحد دلوقتي');
  await expect(page.getByText('البحث لا يذكر حسابات أو قياسات أو فئة IFRA').first()).toBeVisible();

  await page.goto('/templates?search=skincare');
  await page.goto('/templates/face_cream_basic');
  await expect(page.getByTestId('theoretical-alert')).toBeVisible();
  const lines = page.getByTestId('template-lines');
  await expect(lines).toContainText('الباقي (q.s.)');
  await expect(lines).toContainText('مطرٍّ');
  await expect(lines).toContainText('مستحلب');
  await expect(lines).toContainText('غير متوفرة');
  await expectNoHorizontalScroll(page);
});

test('closure compatibility: nothing is declared at first, a declared pair passes, an undeclared one is refused', async ({ page }) => {
  const container = `Spec Bottle ${suffix}`;
  const closureOk = `Spec Pump ${suffix}`;
  const closureNo = `Spec Cap ${suffix}`;
  const finish = `T${suffix.toUpperCase()}`;
  execSync(
    `php artisan tinker --execute='$s = App\\Models\\Source::query()->firstOrFail(); foreach ([["${container}", "container", "${finish}"], ["${closureOk}", "pump_sprayer", "${finish}-P"], ["${closureNo}", "closure", "${finish}-X"]] as [$n, $k, $f]) { App\\Models\\TradeMaterial::query()->create(["trade_name" => $n, "item_kind" => $k, "neck_finish" => $f, "status" => "active", "packaging_source_id" => $s->id, "packaging_confidence" => "L2_supplier_tds"]); }'`,
    { cwd: 'apps/api', stdio: 'pipe' },
  );

  await page.goto('/packaging');
  await page.getByTestId('packaging-compat-link').click();
  await expect(page).toHaveURL(/\/packaging\/compatibility/);
  await expect(page.getByRole('heading', { level: 1, name: 'توافق الأغطية' })).toBeVisible();

  const runCheck = async (closure: string) => {
    await pickRecord(page, page.getByTestId('check-container'), container);
    await pickRecord(page, page.getByTestId('check-closure'), closure);
    await page.getByTestId('check-run').click();
    return page.getByTestId('check-result');
  };

  // Nothing declared: refused.
  const refused = await runCheck(closureOk);
  await expect(refused).toHaveAttribute('data-status', 'FAIL');
  await expect(refused).toContainText('مرفوض');

  // Declare the pump pair with a new source's statement.
  await page.getByTestId('compat-new').click();
  const dialog = page.getByRole('dialog');
  await dialog.getByTestId('compat-container-neck').fill(finish);
  await dialog.getByTestId('compat-closure-neck').fill(`${finish}-P`);
  await pickRecord(page, dialog.getByRole('combobox', { name: /^مصدر/ }), DEMO_SOURCE);
  await pickOption(page, dialog.getByTestId('compat-confidence'), CONFIDENCE_REFERENCE);
  await dialog.getByTestId('compat-submit').click();
  await expect(page.getByText('تم إعلان الزوج.')).toBeVisible();
  await expect(page.getByRole('row', { name: new RegExp(finish) })).toBeVisible();

  // The declared pair passes and names its source; the undeclared cap is still refused.
  const passed = await runCheck(closureOk);
  await expect(passed).toHaveAttribute('data-status', 'PASS');
  await expect(passed).toContainText(DEMO_SOURCE);
  const stillRefused = await runCheck(closureNo);
  await expect(stillRefused).toHaveAttribute('data-status', 'FAIL');

  // Declaring it again says so; the list filter finds it; withdrawing it refuses the pump again.
  await page.getByTestId('compat-new').click();
  await page.getByRole('dialog').getByTestId('compat-container-neck').fill(` ${finish.toLowerCase()} `);
  await page.getByRole('dialog').getByTestId('compat-closure-neck').fill(`${finish}-P`);
  await pickRecord(page, page.getByRole('dialog').getByRole('combobox', { name: /^مصدر/ }), DEMO_SOURCE);
  await pickOption(page, page.getByRole('dialog').getByTestId('compat-confidence'), CONFIDENCE_REFERENCE);
  await page.getByRole('dialog').getByTestId('compat-submit').click();
  await expect(page.getByRole('dialog')).toContainText('هذا الزوج معلَن من قبل');
  await page.keyboard.press('Escape');

  await page.goto(`/packaging/compatibility?search=${encodeURIComponent(finish)}`);
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await page.locator('tbody tr').first().getByRole('button', { name: 'سحب' }).click();
  await page.getByRole('alertdialog').getByRole('button', { name: 'سحب' }).click();
  await expect(page.getByText('تم سحب الزوج.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'لا توجد نتائج تطابق هذه الفلاتر' })).toBeVisible();

  // And with the pair withdrawn the pump is refused again.
  await page.goto('/packaging/compatibility');
  await pickRecord(page, page.getByTestId('check-container'), container);
  await pickRecord(page, page.getByTestId('check-closure'), closureOk);
  await page.getByTestId('check-run').click();
  await expect(page.getByTestId('check-result')).toHaveAttribute('data-status', 'FAIL');
  await expectNoHorizontalScroll(page);
});
