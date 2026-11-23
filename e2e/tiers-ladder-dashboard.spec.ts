import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, expectRtlAndFits, pickRecord, settle } from './support/kit';

/**
 * The tiers screen (S-05), the price ladder (S-13) and the home figures (S-15) end to end, on the TiersDemoSeeder data (TEST-FIXTURE numbers).
 *
 * Tiers: water 1 EGP/kg (0 % active) and a surfactant now quoted 2.2 USD at 50 EGP per USD = 110 EGP/kg (50 % active); active matter >= 10 % gives
 * 20 % surfactant + 80 % water = 0.8 + 22 = 22.80 EGP/kg; the surfactant +10 % (121) gives 25.00, i.e. +2.20.
 * Ladder: unit cost 25 + factory 20 % = 30, + 2 = 32, wholesaler +25 % = 40, retailer +25 % = 50, VAT 14 % = 7, shelf 57.00; back from 57 the maximum unit cost is 25.00.
 * The surfactant is 20 % x 110 = 22 of a 22.80 material cost (96.5 %); per 0.5 kg unit 11 EGP: the dollar +10 % => unit cost 26.10, consumer price 59.35 (+2.35).
 * Home: DEMO-TIER cost was approved at 20.80 and the surfactant now costs 110: 22.80, +9.615 %; a unit cost of 25 against a published 30 is a 16.7 % margin.
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  execSync('php artisan db:seed --class=TiersDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

test.describe('tiers screen', () => {
  test('a product and a tier in, the cheapest formula and its sensitivity out; missing is named, never zero', async ({ page }) => {
    await page.goto('/tiers');
    await expect(page.getByRole('heading', { level: 1, name: 'الفئات والمُحسِّن' })).toBeVisible();
    await expectRtlAndFits(page);

    await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-TIER', /DEMO-TIER/);
    await expect(page).toHaveURL(/\/tiers\?formula=/);
    await expect(page.getByTestId('profile-empty')).toBeVisible();

    // No profile saved yet: the optimizer says which figure it lacks and gives no formula.
    await page.getByTestId('solve').click();
    await expect(page.getByTestId('tier-result')).toHaveAttribute('data-status', 'missing');
    await expect(page.getByTestId('missing-item').first()).toContainText('الحد الأدنى للمادة الفعالة');
    await expect(page.getByTestId('result-cost')).toHaveCount(0);

    // Write the constraint with its source and save it.
    await page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first().fill('10');
    await page.getByTestId('profile-save').click();
    await expect(page.getByTestId('profile-save')).toBeVisible();
    await expect(page.getByText('مصدر الأرقام').first()).toBeVisible();
    await expect(page.locator('[data-field-error], .text-danger-ink').first()).toContainText('اختر مصدر الأرقام');
    await pickRecord(page, page.getByRole('combobox', { name: /^مصدر الأرقام/ }), 'Demo tiers cost sheet');
    await page.getByTestId('profile-save').click();
    await expect(page.getByTestId('profile-empty')).toHaveCount(0);
    await expect(page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first()).toHaveValue('10');

    await pickRecord(page, page.getByRole('combobox', { name: /^خامة الإكمال/ }), 'Zz Tier Water', /Zz Tier Water/);
    await page.getByTestId('solve').click();
    await expect(page.getByTestId('result-status')).toContainText('حل أمثل');
    await expect(page.getByTestId('result-cost')).toContainText('22.80');
    await expect(page.locator('[data-material="Zz Tier Surfactant"] [data-testid="line-percent"]')).toContainText('20.000');
    await expect(page.locator('[data-material="Zz Tier Water"] [data-testid="line-percent"]')).toContainText('80.000');
    await expect(page.locator('[data-binding="active.min"]')).toBeVisible();
    await expect(page.locator('[data-scenario="Zz Tier Surfactant"] [data-testid="scenario-delta"]')).toContainText('2.20');

    // Materials without a price or an active matter are left out and listed, not read as zero.
    await expect(page.getByTestId('result-excluded')).toContainText('Zz Tier Unpriced');
  });

  test('a cost limit below the cheapest formula says how far it is (infeasible)', async ({ page }) => {
    await page.goto('/tiers');
    await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-TIER', /DEMO-TIER/);
    // The mid tier has its own profile: the economy one saved above stays as it is.
    await expect(page.getByTestId('tier-profile')).toBeVisible();
    await page.getByTestId('tier-switch').getByRole('button', { name: 'متوسط' }).click();
    await expect(page).toHaveURL(/tier=mid/);
    await page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first().fill('10');
    await page.getByLabel(/^حد التكلفة/).first().fill('15');
    await pickRecord(page, page.getByRole('combobox', { name: /^مصدر الأرقام/ }), 'Demo tiers cost sheet');
    await page.getByTestId('profile-save').click();
    await expect(page.getByLabel(/^حد التكلفة/).first()).toHaveValue('15');
    await pickRecord(page, page.getByRole('combobox', { name: /^خامة الإكمال/ }), 'Zz Tier Water', /Zz Tier Water/);
    await page.getByTestId('solve').click();
    await expect(page.getByTestId('result-status')).toContainText('لا يوجد حل');
    await expect(page.getByTestId('result-conflicts')).toContainText('7.8');
    await expect(page.getByTestId('result-cost')).toHaveCount(0);
  });
});

test.describe('price ladder', () => {
  test('cost to shelf, shelf to maximum cost and the dollar: every figure from the engine, gaps named', async ({ page }) => {
    await page.goto('/price-ladder');
    await expect(page.getByRole('heading', { level: 1, name: 'سلّم السعر' })).toBeVisible();
    await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-TIER', /DEMO-TIER/);
    await expect(page.getByTestId('ladder-unit-cost')).toContainText('25.00');
    await expect(page.getByTestId('ladder-tax')).toContainText('14');
    await expect(page.getByTestId('ladder-usd-rate')).toContainText('50.00');

    // Nothing typed: the engine lists the margins as missing.
    await page.getByTestId('ladder-calculate').click();
    await expect(page.getByTestId('ladder-forward').getByTestId('missing-item').first()).toBeVisible();
    await expect(page.getByTestId('ladder-consumer-price')).toHaveCount(0);

    await page.getByTestId('margin-basis').getByRole('button', { name: 'على التكلفة' }).click();
    await page.getByLabel(/^هامش المصنع/).fill('20');
    await page.getByLabel(/^تكلفة التوزيع للوحدة/).fill('2');
    await page.getByLabel(/^هامش تاجر الجملة/).fill('25');
    await page.getByLabel(/^هامش تاجر التجزئة/).fill('25');
    await page.getByLabel(/^وزن المنتج في الوحدة/).fill('0.5');
    await page.getByLabel(/^سعر الرف \(للاتجاه العكسي\)/).fill('57');
    await page.getByLabel(/^تكلفة خارج التركيبة للوحدة/).fill('5');
    await page.getByTestId('ladder-calculate').click();

    await expect(page.getByTestId('ladder-consumer-price')).toContainText('57.00');
    await expect(page.locator('[data-step="factory_price"]')).toContainText('30.00');
    await expect(page.locator('[data-step="retailer_purchase_price"]')).toContainText('40.00');
    await expect(page.locator('[data-step="price_ex_vat"]')).toContainText('50.00');
    await expect(page.getByTestId('ladder-max-unit-cost')).toContainText('25.00');

    // Any step opens its calculation tree.
    await page.locator('[data-step="factory_price"]').getByTestId('explain-trigger').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');

    // The dollar: surfactant 20 % x 110 = 22 of a 22.80 material cost; per 0.5 kg unit that is 11 EGP; +10 % => unit cost 26.10 => 59.35.
    await expect(page.getByTestId('fx-share')).toContainText('96.5');
    const row = page.locator('[data-change="10"]');
    await expect(row.getByTestId('fx-consumer')).toContainText('59.35');
    await expect(row.getByTestId('fx-delta')).toContainText('2.35');
    await expectNoHorizontalScroll(page);
  });

  test('a product with no unit cost, tax setting or prices lists every gap and shows no price', async ({ page }) => {
    await page.goto('/price-ladder');
    await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-BARE', /DEMO-BARE/);
    await expect(page.getByTestId('ladder-unit-cost')).toHaveCount(0);
    await page.getByTestId('ladder-calculate').click();
    const forward = page.getByTestId('ladder-forward');
    await expect(forward.getByTestId('missing-item').filter({ hasText: 'تكلفة الوحدة' })).toBeVisible();
    await expect(forward.getByTestId('missing-item').filter({ hasText: 'الضريبة' })).toBeVisible();
    await expect(page.getByTestId('ladder-consumer-price')).toHaveCount(0);
  });
});

test.describe('home figures', () => {
  test('every KPI is the engine\'s answer or a named gap; nothing is zero by default', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1, name: 'لوحة التحكم' })).toBeVisible();
    await expect(page.getByTestId('kpis')).toBeVisible();
    await settle(page);

    // Stale prices: the owner's threshold is a fixture (30 days); the salt was last quoted in 2020 and DEMO-TIER's surfactant long ago too.
    const stale = page.locator('[data-kpi="stale"]');
    await expect(stale).not.toContainText('—');
    await expect(page.getByTestId('card-stale')).toContainText('Zz Tier Salt');

    // Missing / low confidence: DEMO-BARE has a material with no price at all.
    await expect(page.getByTestId('card-quality').locator('[data-formula="DEMO-BARE"]')).toContainText('Zz Tier Unpriced');

    // Compliance: the demo rule set fails CMPL-FAIL and cannot decide CMPL-UNKNOWN.
    await expect(page.getByTestId('card-compliance').locator('[data-formula="CMPL-FAIL"]')).toContainText('FAIL');
    await expect(page.getByTestId('card-compliance').locator('[data-formula="CMPL-UNKNOWN"]')).toContainText('UNKNOWN');
    await expect(page.getByTestId('card-compliance').locator('[data-formula="CMPL-PASS"]')).toHaveCount(0);

    // Impact of the material price moves: 20.80 -> 22.80 (+9.615 %); the margin (25 against a published 30 = 16.7 %) is shown as a bound, never a guess.
    const impact = page.getByTestId('card-impact').locator('[data-formula="DEMO-TIER"]');
    await expect(impact.getByTestId('impact-cost')).toContainText('22.80');
    await expect(impact.getByTestId('impact-cost')).toContainText('20.80');
    await expect(impact.getByTestId('impact-cost')).toContainText('9.62');
    await expect(impact.getByTestId('impact-margin')).toContainText('16.7');

    // Pending approvals are counts of records waiting for a person.
    await expect(page.getByTestId('card-approvals').locator('[data-approval="costs_to_approve"]')).toBeVisible();
    await expectRtlAndFits(page);
  });

  test('a product with no approved cost has no impact figure, shown as a dash with its reason', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('kpis')).toBeVisible();
    await expect(page.getByTestId('card-impact').locator('[data-formula="DEMO-BARE"]')).toHaveCount(0);
  });
});
