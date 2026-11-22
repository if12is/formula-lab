import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, setTheme, settle, stamp } from './support/kit';

/**
 * Review screenshots of the product family registry and the template library (K-01, K-02, K-06, K-09): the families list and a
 * family page, the templates list and a template page, the use form refusing an unfilled template and the draft formula it
 * creates once filled; in Arabic RTL, light and dark, on a desktop and a 390px phone, at deviceScaleFactor 2.
 * Saved to orchestration/evidence/K-families/. Every shot also asserts the page is RTL and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/K-families';

test.beforeAll(() => {
  execSync('php artisan db:seed --class=FamilyDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        await page.goto('/families');
        await expect(page.getByRole('heading', { level: 1, name: 'عائلات المنتجات' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('families-list');

        await page.goto('/families/dish_liquid');
        await expect(page.getByRole('heading', { level: 1, name: 'سائل أطباق' })).toBeVisible();
        await shot('family-dish-liquid');

        await page.goto('/families/bathroom_cleaner');
        await expect(page.getByTestId('family-properties')).toContainText('لسه مش بيتسجل');
        await shot('family-bathroom-not-recordable');

        await page.goto('/templates');
        await expect(page.getByRole('heading', { level: 1, name: 'قوالب التركيبات' })).toBeVisible();
        await expect(page.locator('tbody tr, [data-mobile-table] li').first()).toBeVisible();
        await shot('templates-list');

        await page.goto('/templates/shampoo_basic');
        await expect(page.getByTestId('theoretical-alert')).toBeVisible();
        await shot('template-shampoo');

        await page.goto('/templates/dish_liquid_basic');
        await page.getByTestId('use-template').click();
        const form = page.getByTestId('use-template-form');
        await form.getByLabel(/^كود التركيبة/).fill(`EV-${theme}-${device}-${stamp()}`.toUpperCase());
        await form.getByLabel(/^اسم التركيبة/).fill('Dish liquid');
        await page.getByTestId('use-template-submit').click();
        await expect(form.getByText('اختار خامة للسطر ده.').first()).toBeVisible();
        await shot('use-template-refused');

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
        await shot('use-template-filled');
        await page.getByTestId('use-template-submit').click();

        await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
        await expect(page.getByRole('textbox', { name: 'نسبة السطر 1' })).toHaveValue('85.8');
        await shot('formula-from-template');
      });
    });
  }
}
