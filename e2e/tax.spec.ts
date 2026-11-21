import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_TDS, DEMO_SOURCE, expectNoHorizontalScroll, expectRtlAndFits, pickOption, pickRecord, seedFormulationDemo, setTheme, settle } from './support/kit';

/**
 * Tax per product (owner decision 2026-11-21) and the soap property map as a selectable source.
 * Hand-computed cases (price before tax 100.00): 14% -> tax 14.00, price 114.00 | 0% -> tax line 0.00, price 100.00 |
 * disabled -> NO tax line, price 100.00. Soap map (SoapCalc convention, one oil of 100 g):
 * lauric 50, myristic 20, palmitic 10, stearic 5, oleic 10, linoleic 3, linolenic 1, ricinoleic 1 =>
 * hardness 85 (50+20+10+5), cleansing 70, conditioning 15 (10+1+3+1), bubbly 71 (50+20+1), creamy 16 (10+5+1).
 * Review screenshots (light + dark, desktop + 390px phone, Arabic RTL, plus one English LTR) go to orchestration/evidence/TAX/.
 * One worker runs the whole file in order: the tests share one formula whose tax setting they change.
 */
const OUT = 'orchestration/evidence/TAX';
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  seedFormulationDemo();
});

function resetTax(): void {
  execSync(`php artisan tinker --execute='App\\Models\\Formula::query()->where("code", "DEMO-SHAMPOO")->update(["tax_mode" => null, "tax_rate_pct" => null]);'`, { cwd: 'apps/api', stdio: 'pipe' });
}

async function openEditor(page: Page): Promise<void> {
  await page.goto('/formulas?search=DEMO-SHAMPOO');
  await page.getByText('DEMO-SHAMPOO').first().click();
  await expect(page).toHaveURL(/\/versions\/3\/edit/);
  await expect(page.getByTestId('tax-panel')).toBeVisible();
}

const panel = (page: Page) => page.getByTestId('tax-panel');

async function choose(page: Page, option: string): Promise<void> {
  await pickOption(page, panel(page).getByRole('combobox', { name: 'إعداد الضريبة' }), option);
}

async function save(page: Page): Promise<void> {
  const saved = page.waitForResponse((r) => r.url().includes('/tax') && r.request().method() === 'PUT');
  await page.getByTestId('tax-save').click();
  expect((await saved).status()).toBeLessThan(400);
  await expect(page.getByTestId('tax-save')).toBeDisabled();
}

test.describe('tax panel behaviour', () => {
  test('not set, then 14%, 0%, disabled: the engine computes the tax line and the state is always explicit', async ({ page }) => {
    resetTax();
    await openEditor(page);

    // No choice yet: said clearly, never read as 0%.
    await expect(page.getByTestId('tax-state')).toHaveAttribute('data-state', 'not_set');
    await expect(page.getByTestId('tax-note')).toContainText('لم تُحدَّد الضريبة');
    await page.getByLabel('السعر قبل الضريبة', { exact: true }).fill('100.00');
    await expect(page.getByTestId('tax-amount')).toHaveCount(0);

    // 14%: tax 100.00 x 14 / 100 = 14.00, price 114.00.
    await choose(page, 'ضريبة بنسبة 14٪');
    await expect(page.getByTestId('tax-state')).toHaveAttribute('data-state', 'applied');
    await expect(page.getByTestId('tax-amount')).toContainText('14.00');
    await expect(page.getByTestId('tax-price-after')).toContainText('114.00');
    await expect(page.getByTestId('tax-regime')).toBeVisible();
    await expect(page.getByTestId('tax-verification')).toContainText('بانتظار التحقق');
    await expect(page.getByTestId('tax-pending')).toBeVisible();
    await save(page);
    await page.reload();
    await expect(page.getByTestId('tax-state')).toHaveAttribute('data-state', 'applied');
    await expect(page.getByTestId('tax-state')).toContainText('14%');

    // 0%: a tax line that is zero, price 100.00.
    await choose(page, 'ضريبة بنسبة 0٪');
    await page.getByLabel('السعر قبل الضريبة', { exact: true }).fill('100.00');
    await expect(page.getByTestId('tax-line')).toHaveAttribute('data-has-line', 'true');
    await expect(page.getByTestId('tax-amount')).toContainText('0.00');
    await expect(page.getByTestId('tax-price-after')).toContainText('100.00');
    await expect(page.getByTestId('tax-note')).toContainText('0٪');
    await save(page);

    // Disabled: NO tax line at all, the price stays 100.00 and the state says so.
    await choose(page, 'الضريبة معطّلة (بدون سطر ضريبة)');
    await page.getByLabel('السعر قبل الضريبة', { exact: true }).fill('100.00');
    await expect(page.getByTestId('tax-state')).toHaveAttribute('data-state', 'disabled');
    await expect(page.getByTestId('tax-line')).toHaveAttribute('data-has-line', 'false');
    await expect(page.getByTestId('tax-amount')).toHaveCount(0);
    await expect(page.getByTestId('tax-no-line')).toBeVisible();
    await expect(page.getByTestId('tax-price-after')).toContainText('100.00');
    await expect(page.getByTestId('tax-regime')).toHaveCount(0);
    await save(page);
    await page.reload();
    await expect(page.getByTestId('tax-state')).toHaveAttribute('data-state', 'disabled');
  });
});

test.describe('soap property map', () => {
  test('the SoapCalc convention is a selectable source; the indicators are the engine\'s, hand-computed', async ({ page }) => {
    await page.goto('/calculators/saponification');
    await expect(page.getByRole('heading', { level: 1, name: 'التصبن' })).toBeVisible();
    const block = page.getByTestId('soap-properties');

    // Nothing is chosen for the person.
    await expect(block.getByTestId('soap-map-card')).toHaveCount(0);
    await pickOption(page, block.getByRole('combobox', { name: 'مصدر خريطة الخواص' }), 'اصطلاح SoapCalc');
    await expect(block.getByTestId('soap-map-card')).toContainText('اصطلاح SoapCalc');
    await expect(block.getByTestId('soap-map-pending')).toBeVisible();
    await expect(block.getByTestId('soap-map-terms')).toContainText('الصلابة = اللوريك + الميريستيك + البالمتيك + الستيريك');
    await expect(block.getByTestId('soap-not-mapped')).toContainText('رقم اليود');

    await page.getByLabel('كتلة الزيت 1', { exact: true }).fill('100');
    const acids: Record<string, string> = { اللوريك: '50', الميريستيك: '20', البالمتيك: '10', الستيريك: '5', الأوليك: '10', اللينوليك: '3', اللينولينيك: '1', الريسينوليك: '1' };
    for (const [acid, value] of Object.entries(acids)) {
      await block.getByLabel(`${acid} في الزيت 1`, { exact: true }).fill(value);
    }
    // Without the source and confidence of the profile the engine reports it missing, never a number.
    await expect(block.getByTestId('soap-properties-missing')).toBeVisible();

    await pickRecord(page, block.getByRole('combobox', { name: /مصدر التركيب الدهني للزيت 1/ }), DEMO_SOURCE);
    await pickOption(page, block.getByRole('combobox', { name: /ثقة التركيب الدهني للزيت 1/ }), CONFIDENCE_TDS);
    for (const [name, value] of Object.entries({ hardness: '85.00', cleansing: '70.00', conditioning: '15.00', bubbly: '71.00', creamy: '16.00' })) {
      await expect(block.locator(`[data-indicator="${name}"]`)).toContainText(value);
    }
  });
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 1900 }]] as const) {
    test.describe(`evidence ${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, target = page.getByTestId('tax-panel')): Promise<void> => {
          await settle(page);
          await expectRtlAndFits(page);
          const path = `${OUT}/${name}-${theme}-${device}.png`;
          if (device === 'mobile') {
            // The page scrolls inside the shell: bring the panel to the top of a tall 390px viewport and shoot the viewport.
            await target.evaluate((el) => el.scrollIntoView({ block: 'start' }));
            await page.waitForTimeout(300);
            await page.screenshot({ path });
          } else {
            await target.scrollIntoViewIfNeeded();
            await target.screenshot({ path });
          }
        };

        resetTax();
        await openEditor(page);
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await page.getByLabel('السعر قبل الضريبة', { exact: true }).fill('100.00');
        await shot('tax-not-set');

        await choose(page, 'ضريبة بنسبة 14٪');
        await expect(page.getByTestId('tax-amount')).toContainText('14.00');
        await shot('tax-14');

        await choose(page, 'ضريبة بنسبة 0٪');
        await expect(page.getByTestId('tax-amount')).toContainText('0.00');
        await shot('tax-0');

        await choose(page, 'الضريبة معطّلة (بدون سطر ضريبة)');
        await expect(page.getByTestId('tax-no-line')).toBeVisible();
        await shot('tax-disabled');

        await settle(page);
        await expectNoHorizontalScroll(page);
        await page.screenshot({ path: `${OUT}/formula-editor-${theme}-${device}.png`, fullPage: true });

        await page.goto('/calculators/saponification');
        await expect(page.getByRole('heading', { level: 1, name: 'التصبن' })).toBeVisible();
        const block = page.getByTestId('soap-properties');
        await pickOption(page, block.getByRole('combobox', { name: 'مصدر خريطة الخواص' }), 'اصطلاح SoapCalc');
        await page.getByLabel('كتلة الزيت 1', { exact: true }).fill('100');
        const acids: Record<string, string> = { اللوريك: '50', الميريستيك: '20', البالمتيك: '10', الستيريك: '5', الأوليك: '10', اللينوليك: '3', اللينولينيك: '1', الريسينوليك: '1' };
        for (const [acid, value] of Object.entries(acids)) {
          await block.getByLabel(`${acid} في الزيت 1`, { exact: true }).fill(value);
        }
        await pickRecord(page, block.getByRole('combobox', { name: /مصدر التركيب الدهني للزيت 1/ }), DEMO_SOURCE);
        await pickOption(page, block.getByRole('combobox', { name: /ثقة التركيب الدهني للزيت 1/ }), CONFIDENCE_TDS);
        await expect(block.locator('[data-indicator="hardness"]')).toContainText('85.00');
        await shot('soap-property-map', block);

        if (theme === 'dark') {
          await setTheme(page, 'light');
        }
      });
    });
  }
}

test.describe('english', () => {
  test.use({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });

  test('the tax panel in English is left to right and worded from the same lang files', async ({ page }) => {
    resetTax();
    await openEditor(page);
    const saved = page.waitForResponse((r) => r.url().endsWith('/settings/locale') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: /العربية|AR|ع/ }).first().click();
    await page.getByRole('menuitemradio', { name: 'English' }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(panel(page).getByRole('heading', { name: 'Tax', exact: true })).toBeVisible();
    await pickOption(page, panel(page).getByRole('combobox', { name: 'Tax setting' }), 'Tax at 14%');
    await page.getByLabel('Price before tax', { exact: true }).fill('100.00');
    await expect(page.getByTestId('tax-amount')).toContainText('14.00');
    await expect(page.getByTestId('tax-price-after')).toContainText('114.00');
    await settle(page);
    await panel(page).screenshot({ path: `${OUT}/tax-14-en-ltr-light-desktop.png` });

    // Back to Arabic for the specs that follow.
    await page.getByRole('button', { name: /English|EN/ }).first().click();
    await page.getByRole('menuitemradio', { name: 'العربية' }).click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  });
});
