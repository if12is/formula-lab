import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, setTheme, settle } from './support/kit';

/**
 * Review screenshots of the tiers screen (S-05), the price ladder (S-13) and the home figures (S-15): Arabic RTL, light and dark, on a desktop
 * and a 390px phone, at deviceScaleFactor 2, on the TiersDemoSeeder data (TEST-FIXTURE numbers). The tiers shots use the premium tier of the demo
 * product, which no other spec touches; every variant writes the same profile (active matter >= 10 %, source "Demo tiers cost sheet").
 * Saved to orchestration/evidence/S-05-S-13-S-15/. Every shot also asserts the page is RTL and does not scroll sideways.
 */
const OUT = 'orchestration/evidence/S-05-S-13-S-15';

// The four variants share the premium profile of the demo product: they run one after the other and each starts with none saved.
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  execSync('php artisan db:seed --class=TiersDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

test.beforeEach(() => {
  execSync(`php artisan tinker --execute='App\\Models\\TierProfile::query()->where("tier", "premium")->get()->each->delete();'`, { cwd: 'apps/api', stdio: 'pipe' });
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

        // S-15: the home figures.
        await page.goto('/');
        await expect(page.getByTestId('kpis')).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByTestId('card-impact').locator('[data-formula="DEMO-TIER"]')).toBeVisible();
        await shot('home');

        // S-05: the tiers screen, missing then solved (premium tier).
        await page.goto('/tiers');
        await shot('tiers-empty');
        await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-TIER', /DEMO-TIER/);
        await expect(page.getByTestId('tier-profile')).toBeVisible();
        await page.getByTestId('tier-switch').getByRole('button', { name: 'فاخر' }).click();
        await expect(page).toHaveURL(/tier=premium/);
        await page.getByTestId('solve').click();
        await expect(page.getByTestId('tier-result')).toHaveAttribute('data-status', 'missing');
        await shot('tiers-missing');

        await page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first().fill('10');
        await pickRecord(page, page.getByRole('combobox', { name: /^مصدر الأرقام/ }), 'Demo tiers cost sheet');
        await page.getByTestId('profile-save').click();
        await expect(page.getByTestId('profile-empty')).toHaveCount(0);
        await pickRecord(page, page.getByRole('combobox', { name: /^خامة الإكمال/ }), 'Zz Tier Water', /Zz Tier Water/);
        await page.getByTestId('solve').click();
        await expect(page.getByTestId('result-status')).toContainText('حل أمثل');
        await expect(page.getByTestId('result-cost')).toContainText('22.80');
        await shot('tiers-solved');

        // S-13: the price ladder, with a gap and then complete.
        await page.goto('/price-ladder');
        await shot('ladder-empty');
        await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-BARE', /DEMO-BARE/);
        await page.getByTestId('ladder-calculate').click();
        await expect(page.getByTestId('ladder-forward').getByTestId('missing-item').first()).toBeVisible();
        await shot('ladder-missing');

        await page.goto('/price-ladder');
        await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-TIER', /DEMO-TIER/);
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
        await expect(page.locator('[data-change="10"]').getByTestId('fx-consumer')).toContainText('59.35');
        await shot('ladder-result');
      });
    });
  }
}
