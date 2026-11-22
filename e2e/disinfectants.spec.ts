import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, settle } from './support/kit';

/**
 * K-04 / K-05 / N-12: the fabric softener, laundry fragrance and disinfectant families, and the separate EDA path notice. The engine decides
 * (disinfectionPathwayNotice): a disinfectant family, or a disinfection claim, shows the notice; an antibacterial claim (undecided in the
 * sources) asks for a decision; nothing else shows it. Saving the claims keeps them on the formula.
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  execSync('php artisan db:seed --class=DisinfectantDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

const CLAIMS_LABEL = 'الادعاءات اللي المنتج بيعملها';

/** The compliance card of the latest version of a seeded formula. */
async function openEditor(page: Page, code: string): Promise<void> {
  await page.goto(`/formulas?search=${code}`);
  await page.getByText(code, { exact: true }).first().click();
  await expect(page).toHaveURL(/\/versions\/1\/edit/);
  await expect(page.getByTestId('compliance-card')).toBeVisible();
}

async function chooseClaim(page: Page, name: string): Promise<void> {
  await page.getByRole('combobox', { name: CLAIMS_LABEL }).click();
  await page.getByRole('option', { name }).click();
  await page.keyboard.press('Escape');
}

test('the new families are listed with their regulator: softener and laundry fragrance EOS, both disinfectants EDA with no IFRA category stated', async ({ page }) => {
  await page.goto('/families?search=منعم');
  await settle(page);
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('tbody tr').first()).toContainText('EOS');

  await page.goto('/families?group=disinfectants');
  await settle(page);
  const rows = page.locator('tbody tr');
  await expect(rows).toHaveCount(2);
  await expect(rows.first()).toContainText('EDA');
});

test('a fabric softener page: cationic active matter, viscosity and 4-week stability, both recordable in the lab, and no disinfectant notice', async ({ page }) => {
  await page.goto('/families/fabric_softener');
  await expect(page.getByRole('heading', { level: 1, name: 'منعم أقمشة' })).toBeVisible();
  await expect(page.getByTestId('family-facts')).toContainText('10A');
  await expect(page.getByTestId('family-calculations')).toContainText('المادة الفعالة');
  await expect(page.getByTestId('family-properties')).toContainText('اللزوجة');
  await expect(page.getByTestId('family-properties')).toContainText('الثبات بعد 4 أسابيع');
  await expect(page.getByTestId('disinfection-block')).toHaveCount(0);
});

test('an alcohol disinfectant page: the engine shows the separate EDA path notice with the guide, and ethanol strength is a gap in the lab, not a demand', async ({ page }) => {
  await page.goto('/families/alcohol_disinfectant');
  await expect(page.getByRole('heading', { level: 1, name: 'مطهر كحولي' })).toBeVisible();
  const notice = page.getByTestId('disinfection-notice');
  await expect(notice).toHaveAttribute('data-status', 'applies');
  await expect(notice).toContainText('EDREX:GL.CALI.009');
  await expect(notice).toContainText('2026');
  await expect(notice).toContainText('عائلة المنتج مطهرات');
  await expect(notice).toContainText('بينبّه بس');
  await expect(page.getByTestId('family-facts')).toContainText('غير محدد لحد دلوقتي');
  await expect(page.getByTestId('family-calculations')).toContainText('تركيز المادة المطهرة الفعالة');
  await expect(page.getByTestId('family-properties')).toContainText('لسه مش بيتسجل');
  await expectNoHorizontalScroll(page);
});

test('a detergent family page has no disinfectant notice at all', async ({ page }) => {
  await page.goto('/families/dish_liquid');
  await expect(page.getByRole('heading', { level: 1, name: 'سائل أطباق' })).toBeVisible();
  await expect(page.getByTestId('disinfection-block')).toHaveCount(0);
});

test('a formula of a disinfectant family shows the notice in the compliance panel, apart from the rule results', async ({ page }) => {
  await openEditor(page, 'DEMO-DISINFECTANT');
  const notice = page.getByTestId('disinfection-notice');
  await expect(notice).toHaveAttribute('data-status', 'applies');
  await expect(notice).toContainText('مسار EDA المنفصل');
  await expect(page.getByTestId('compliance-card')).toContainText('الادعاءات اللي المنتج بيعملها');
  await expectNoHorizontalScroll(page);
});

test('a shampoo: no claim, no notice; a disinfection claim shows it before saving, saving keeps it, clearing removes it', async ({ page }) => {
  await openEditor(page, 'DEMO-DISINF-SHAMPOO');
  await expect(page.getByTestId('disinfection-none')).toBeVisible();
  await expect(page.getByTestId('disinfection-notice')).toHaveCount(0);

  await chooseClaim(page, 'تطهير');
  await expect(page.getByTestId('disinfection-notice')).toHaveAttribute('data-status', 'applies');
  await expect(page.getByTestId('disinfection-notice')).toContainText('الادعاء: تطهير');
  await page.getByTestId('claims-save').click();
  await expect(page.getByText('الادعاءات اتحفظت على التركيبة.')).toBeVisible();

  await page.reload();
  await expect(page.getByTestId('disinfection-notice')).toHaveAttribute('data-status', 'applies');

  await page.getByRole('combobox', { name: CLAIMS_LABEL }).click();
  await page.getByRole('option', { name: 'تطهير' }).click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('disinfection-none')).toBeVisible();
  await page.getByTestId('claims-save').click();
  await expect(page.getByText('الادعاءات اتحفظت على التركيبة.')).toBeVisible();
});

test('an antibacterial claim is not decided by the sources: the panel asks for a decision instead of saying yes or no', async ({ page }) => {
  await openEditor(page, 'DEMO-DISINF-SHAMPOO');
  await chooseClaim(page, 'مضاد للبكتيريا');
  const notice = page.getByTestId('disinfection-notice');
  await expect(notice).toHaveAttribute('data-status', 'review');
  await expect(notice).toContainText('ادعاء محتاج قرار');
  await expect(page.getByTestId('disinfection-none')).toHaveCount(0);
});
