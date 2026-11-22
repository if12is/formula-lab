import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_REFERENCE, DEMO_SOURCE, expectRtlAndFits, fillSourced, pickOption, pickRecord, seedFormulationDemo, setTheme, settle, stamp } from './support/kit';

/**
 * Review screenshots of phase M (M-01 phase editor, M-02 functional classes, M-03 oil phase to emulsifiers), in light and
 * dark, on a desktop and a 390px phone, at deviceScaleFactor 2. Saved to orchestration/evidence/M-emulsions/. Every shot
 * asserts the page is RTL and does not scroll sideways.
 */
test.beforeAll(() => {
  seedFormulationDemo();
  execSync(
    `php artisan tinker --execute='foreach (["Demo SLES" => ["emulsifier"], "Demo Water" => ["humectant"], "Demo Salt" => ["thickener"], "Demo Fragrance" => ["fragrance"]] as $n => $c) { App\\Models\\TradeMaterial::query()->where("trade_name", $n)->get()->each->update(["functional_classes" => $c]); }'`,
    { cwd: 'apps/api', stdio: 'pipe' },
  );
});

const suffix = stamp();

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 1200 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        test.setTimeout(120_000);
        const shot = async (name: string, target: Page = page): Promise<void> => {
          await settle(target);
          await expectRtlAndFits(target);
          await target.screenshot({ path: `orchestration/evidence/M-emulsions/${name}-${theme}-${device}.png` });
        };
        const scrollTop = () => page.evaluate(() => document.querySelector('main')?.scrollTo(0, 0));

        // M-01: a cream with a water phase, an oil phase and a cool-down phase.
        await page.goto('/formulas/create');
        await settle(page);
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await page.getByLabel(/^الكود/).fill(`M-${theme}-${device}-${suffix}`.toUpperCase());
        await page.getByLabel(/^الاسم\s*\*?$/).fill(`Face cream ${theme} ${device} ${suffix}`);
        await page.getByLabel(/^نوع المنتج/).fill('cream');
        const lines: Array<[string, string, string, string]> = [['Demo SLES', '25', 'الطور الزيتي', '75'], ['Demo Water', '70', 'الطور المائي', '75'], ['Demo Salt', '5', 'طور التبريد', '40']];
        for (const [index, [material, percent, phase, temp]] of lines.entries()) {
          const n = index + 1;
          if (index > 0) {
            await page.getByRole('button', { name: 'إضافة سطر' }).click();
          }
          await pickRecord(page, page.getByRole('combobox', { name: `خامة السطر ${n}` }), material);
          await page.getByRole('textbox', { name: `نسبة السطر ${n}` }).fill(percent);
          await pickOption(page, page.getByRole('combobox', { name: `طور السطر ${n}` }), phase);
          await page.getByRole('textbox', { name: `حرارة إضافة السطر ${n}` }).fill(temp);
        }
        await expect(page.getByTestId('phases-card')).toBeVisible();
        await scrollTop();
        await shot('phase-editor');
        await page.getByTestId('phases-card').scrollIntoViewIfNeeded();
        await shot('phase-editor-totals');

        // The same formula with the phases 5 short of 100: the engine says so.
        await page.getByRole('textbox', { name: 'نسبة السطر 3' }).fill('0');
        await page.getByTestId('phases-card').scrollIntoViewIfNeeded();
        await expect(page.getByTestId('phase-issue')).toBeVisible();
        await shot('phase-editor-mismatch');

        // M-02: the library with its class column and filter, and a material page.
        await page.goto('/materials?functional_class=emulsifier');
        await expect(page.getByText('Demo SLES').first()).toBeVisible();
        await scrollTop();
        await shot('materials-filter');
        await page.goto('/materials?search=Demo%20Salt');
        await page.getByText('Demo Salt').first().click();
        await expect(page).toHaveURL(/\/materials\/[0-9a-f-]{36}/);
        await expect(page.getByRole('heading', { level: 1, name: 'Demo Salt' })).toBeVisible();
        await expect(page.getByText('مكثّف (Thickener)').first()).toBeVisible();
        await page.getByRole('button', { name: 'تعديل' }).click();
        await expect(page.getByRole('dialog').getByLabel('الفئات الوظيفية')).toBeVisible();
        await shot('material-edit');
        await page.keyboard.press('Escape');

        // M-03: oil phase to emulsifiers.
        await page.goto('/calculators/hlb');
        await page.getByRole('button', { name: 'من الزيت إلى المستحلبين' }).click();
        await page.getByLabel('كتلة المكوّن 1', { exact: true }).fill('20');
        await fillSourced(page, 'HLB المطلوب للمكوّن 1', '8', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await page.getByRole('button', { name: 'إضافة مكوّن زيتي' }).click();
        await page.getByLabel('كتلة المكوّن 2', { exact: true }).fill('10');
        await fillSourced(page, 'HLB المطلوب للمكوّن 2', '11', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await fillSourced(page, 'HLB للمستحلب المنخفض', '4.3', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await fillSourced(page, 'HLB للمستحلب المرتفع', '15.0', DEMO_SOURCE, CONFIDENCE_REFERENCE);
        await page.getByLabel('كتلة المستحلبين معاً', { exact: true }).fill('107');
        await expect(page.locator('[data-result="mass_high"]')).toContainText('47.000');
        await scrollTop();
        await shot('hlb-system');
        if (device === 'mobile') {
          await page.getByTestId('results-card').scrollIntoViewIfNeeded();
          await shot('hlb-system-results');
        }
        await pickOption(page, page.getByTestId('high-ionicity'), 'كاتيوني');
        await expect(page.getByTestId('missing')).toBeVisible();
        await page.getByTestId('missing').scrollIntoViewIfNeeded();
        await shot('hlb-system-ionic-refused');

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}
