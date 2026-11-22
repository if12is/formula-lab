import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, settle, stamp } from './support/kit';

/**
 * K-01 / K-02 / K-06 / K-09: the product family registry and the template library. Hand-checked: 17 families (9 detergents + 6
 * personal care + 2 disinfectants); a template line's share is never invented; using a template runs the engine first, and the formula it creates
 * is the engine's answer (12 + 1.5 + 0.2 + 0.5 = 14.2, so the q.s. water is 85.8).
 */
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  execSync('php artisan db:seed --class=FamilyDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

const code = `DISH-${stamp()}`.toUpperCase();

test('families list: 17 families, regulator and IFRA filters live in the address, and a no-match filter says so', async ({ page }) => {
  await page.goto('/families');
  await expect(page.getByRole('heading', { level: 1, name: 'عائلات المنتجات' })).toBeVisible();
  await settle(page);
  const rows = page.locator('tbody tr');
  await expect(rows).toHaveCount(17);

  await page.goto('/families?regulator=EDA');
  await expect(rows).toHaveCount(8);
  await page.goto('/families?ifra_category=10B');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('منظف زجاج');

  await page.goto('/families?search=شامبو');
  await expect(rows).toHaveCount(1);

  await page.goto('/families?search=لا-يوجد');
  await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر')).toBeVisible();
});

test('a family page shows what to calculate and measure, and "not stated" for the tax regime (never a guess)', async ({ page }) => {
  await page.goto('/families/bathroom_cleaner');
  await expect(page.getByRole('heading', { level: 1, name: 'منظف حمامات' })).toBeVisible();
  await expect(page.getByTestId('family-facts')).toContainText('EOS');
  await expect(page.getByTestId('family-facts')).toContainText('غير محدد لحد دلوقتي');
  await expect(page.getByTestId('family-calculations')).toContainText('التعادل');
  await expect(page.getByTestId('family-properties')).toContainText('التآكل');
  await expect(page.getByTestId('family-properties')).toContainText('لسه مش بيتسجل');
  await expectNoHorizontalScroll(page);
});

test('templates list filters by status and the library has no approved template', async ({ page }) => {
  await page.goto('/templates');
  await expect(page.getByRole('heading', { level: 1, name: 'قوالب التركيبات' })).toBeVisible();
  await settle(page);
  await expect(page.locator('tbody tr')).toHaveCount(17);
  await page.goto('/templates?status=chemist_approved');
  await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر')).toBeVisible();
  await page.goto('/templates?status=theoretical');
  await expect(page.locator('tbody tr')).toHaveCount(17);
});

test('a template shows its lines by role with no invented share', async ({ page }) => {
  await page.goto('/templates/dish_liquid_basic');
  await expect(page.getByTestId('theoretical-alert')).toBeVisible();
  const lines = page.getByTestId('template-lines');
  await expect(lines).toContainText('ماء');
  await expect(lines).toContainText('الباقي (q.s.)');
  await expect(lines).toContainText('غير متوفرة');
  await expectNoHorizontalScroll(page);
});

test('using a template: the engine refuses an unfilled one, then creates the draft formula with the water balanced', async ({ page }) => {
  await page.goto('/templates/dish_liquid_basic');
  await page.getByTestId('use-template').click();
  const form = page.getByTestId('use-template-form');
  await form.getByLabel(/^كود التركيبة/).fill(code);
  await form.getByLabel(/^اسم التركيبة/).fill('Dish e2e');
  await page.getByTestId('use-template-submit').click();
  await expect(form.getByText('اختار خامة للسطر ده.').first()).toBeVisible();

  const pick = async (role: string, material: string): Promise<void> => {
    await pickRecord(page, form.getByRole('combobox', { name: new RegExp(`^خامة سطر ${role}`) }), material);
  };
  await pick('ماء', 'Family demo Water');
  await pick('سطحي نشط أنيوني', 'Family demo Anionic base');
  await pick('ملح \\(للزوجة\\)', 'Family demo Salt');
  await pick('ضابط pH', 'Family demo Acid');
  await pick('حافظ', 'Family demo Preservative');
  await form.getByRole('textbox', { name: /^نسبة سطر سطحي نشط أنيوني/ }).fill('12');
  await form.getByRole('textbox', { name: /^نسبة سطر ملح/ }).fill('1.5');
  await form.getByRole('textbox', { name: /^نسبة سطر ضابط pH/ }).fill('0.2');
  await form.getByRole('textbox', { name: /^نسبة سطر حافظ/ }).fill('0.5');
  await page.getByTestId('use-template-submit').click();

  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  await expect(page.getByText('Family demo Water').first()).toBeVisible();
  // The q.s. line shows the engine's balance: 100 - 14.2 = 85.8.
  await expect(page.getByRole('textbox', { name: 'نسبة السطر 1' })).toHaveValue('85.8');
  await expect(page.getByTestId('sum')).toContainText('100');
});
