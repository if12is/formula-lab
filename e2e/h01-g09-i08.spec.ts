import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickOption, pickRecord, setTheme, settle, stamp } from './support/kit';

/**
 * H-01 packaging library, I-08 auditable cost report and G-09 publish to Sanad, end to end, in Arabic RTL, light and dark, on a
 * desktop and a 390px phone. Each combination runs the whole journey on a formula of its own (the seeder takes the code), so the
 * runs never share a price or an approval:
 *   packaging: the form refuses a spec without a source and a brimful below nominal, then saves; the list filters live in the URL;
 *   cost report: the editor's cost and the report's total are the same figure, each line says invoice or manual entry with its source,
 *   confidence and date, the explain tree opens, the print view hides the shell;
 *   publish: blocked while the cost is not approved, the approval, then the publish (the outbox payload carries the approved cost,
 *   SKU, barcode and the three prices), and a price rise beyond the limit blocks it again.
 * Hand-computed (TEST-FIXTURE numbers): A 60 % x 10 + B 40 % x 25 = 6 + 10 = 16 EGP/kg; a rise of A to 10.6 is 6 %, above the 5 % limit.
 * Screenshots go to orchestration/evidence/H01-G09-I08/.
 */
const OUT = 'orchestration/evidence/H01-G09-I08';
const artisan = (command: string, env: Record<string, string> = {}): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8', env: { ...process.env, ...env } });

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('packaging, cost report and publish', async ({ page }) => {
        test.setTimeout(180_000);
        const run = `${stamp()}-${theme}-${device}`;
        const code = `E2E-COST-${run}`;
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };
        artisan('db:seed --class=CostingDemoSeeder --force', { COSTING_DEMO_CODE: code });

        // ---- H-01 packaging library ---------------------------------------------------------------------------------
        await page.goto('/packaging');
        await expect(page.getByRole('heading', { level: 1, name: 'مكونات التغليف' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByText('Demo PET bottle 400 mL').first()).toBeVisible();
        // A label with no spec reads "not recorded", never zero.
        await expect(page.getByTestId('packaging-row-' + (await page.getByText('Demo front label').first().evaluate((el) => el.closest('button')?.getAttribute('data-testid')?.replace('packaging-row-', '') ?? ''))).first()).toBeVisible();
        await shot('packaging-list');

        await page.getByTestId('packaging-new').click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel(/^الاسم/).fill(`Spec bottle ${run}`);
        await pickOption(page, dialog.getByRole('combobox', { name: /^الخامة/ }), 'PET');
        await dialog.getByLabel(/^السعة الاسمية/).fill('500');
        await dialog.getByLabel(/^سعة الحافة/).fill('480');
        await dialog.getByTestId('packaging-submit').click();
        await expect(dialog).toContainText('سعة الحافة مينفعش تبقى أصغر من السعة الاسمية.');
        await expect(dialog).toContainText('قيمة المواصفات محتاجة مصدرها ودرجة ثقتها.');
        await shot('packaging-form-errors');

        await dialog.getByLabel(/^سعة الحافة/).fill('520');
        await dialog.getByLabel(/^تسنين العنق/).fill('28/410');
        await pickRecord(page, dialog.getByRole('combobox', { name: /^مصدر المواصفات/ }), 'Demo bottle drawing');
        await pickOption(page, dialog.getByRole('combobox', { name: /^ثقة المواصفات/ }), 'نشرة بيانات المورد (TDS)');
        await dialog.getByTestId('packaging-submit').click();
        await expect(page.getByText('اتضاف مكوّن التغليف.')).toBeVisible();
        // The list is paginated and earlier runs leave rows behind, so look the new one up by search.
        await page.goto(`/packaging?search=${encodeURIComponent(`Spec bottle ${run}`)}`);
        await expect(page.getByText(`Spec bottle ${run}`).first()).toBeVisible();

        // The list's state lives in the URL: a kind filter and a capacity range narrow it.
        await page.goto('/packaging?item_kind=closure');
        await expect(page.getByText('Demo flip-top cap 28/410').first()).toBeVisible();
        await expect(page.getByText('Demo PET bottle 400 mL')).toHaveCount(0);
        await page.goto('/packaging?nominal_capacity_ml_min=450&packaging_material=pet');
        await expect(page.getByText('Spec bottle').first()).toBeVisible();
        await expect(page.getByText('Demo PET bottle 400 mL')).toHaveCount(0);
        await expect(page.getByText('Demo glass jar 250 mL')).toHaveCount(0);
        await shot('packaging-filtered');

        // ---- the editor's cost, then I-08 -----------------------------------------------------------------------------
        await page.goto(`/formulas?search=${code}`);
        await page.getByText(code).first().click();
        await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
        await expect(page.getByTestId('cost-per-kg')).toContainText('16.00');
        const editorCost = (await page.getByTestId('cost-per-kg').innerText()).match(/\d+\.\d{2}/)?.[0];
        await page.getByTestId('open-cost-report').click();
        await expect(page.getByTestId('cost-report')).toBeVisible();

        const total = page.getByTestId('report-total');
        await expect(total).toContainText('16.00');
        expect((await total.innerText()).match(/\d+\.\d{2}/)?.[0]).toBe(editorCost);

        const lines = page.getByTestId('report-lines').locator('li[data-testid^="report-line-"]');
        await expect(lines).toHaveCount(2);
        await expect(lines.nth(0).getByTestId('line-origin')).toHaveAttribute('data-origin', 'invoice');
        await expect(lines.nth(0).getByTestId('line-source')).toContainText('INV-2026-0042');
        await expect(lines.nth(0).getByTestId('line-share')).toContainText('6.0000');
        await expect(lines.nth(0).getByTestId('line-rejected')).toContainText('ثقة أقل');
        await expect(lines.nth(0).getByTestId('line-invoices')).toContainText('Demo Packaging Co.');
        await expect(lines.nth(1).getByTestId('line-origin')).toHaveAttribute('data-origin', 'manual');
        await expect(lines.nth(1).getByTestId('line-share')).toContainText('10.0000');
        await expect(page.getByTestId('approval-none')).toBeVisible();
        await shot('cost-report');

        await total.getByTestId('explain-trigger').click();
        await expect(page.getByTestId('trace-tree')).toBeVisible();
        await shot('cost-report-explain');
        await page.keyboard.press('Escape');

        // The print view: the shell goes away, the figures stay.
        await page.emulateMedia({ media: 'print' });
        await expect(page.locator('.kit-side')).toBeHidden();
        await expect(page.getByTestId('report-lines')).toBeVisible();
        await expect(page.getByTestId('approval-none')).toBeHidden();
        await page.screenshot({ path: `${OUT}/cost-report-print-${theme}-${device}.png`, fullPage: true });
        await page.emulateMedia({ media: 'screen' });

        // ---- G-09: blocked, approve, publish -----------------------------------------------------------------------------
        await page.getByTestId('to-publish').click();
        await expect(page.getByTestId('publish-page')).toBeVisible();
        await expect(page.getByTestId('publish-decision')).toHaveAttribute('data-state', 'blocked');
        await expect(page.getByTestId('decision-reasons').locator('[data-rule="PS-01"]')).toBeVisible();
        await expect(page.getByTestId('publish-submit')).toBeDisabled();
        await page.getByTestId('publish-decision').scrollIntoViewIfNeeded();
        await shot('publish-blocked-cost-not-approved');

        await page.getByTestId('to-report').click();
        await page.getByTestId('approve-cost').click();
        await expect(page.getByText('اتعتمدت التكلفة.')).toBeVisible();
        await expect(page.getByTestId('approval-state')).toHaveAttribute('data-state', 'in_force');
        await shot('cost-report-approved');

        await page.getByTestId('to-publish').click();
        await expect(page.getByTestId('approved-cost')).toContainText('18.46');
        await expect(page.getByTestId('publish-tax')).toHaveAttribute('data-set', 'true');
        // The selling price is still empty: the price waterfall is incomplete.
        await expect(page.getByTestId('publish-decision')).toHaveAttribute('data-state', 'blocked');
        await expect(page.getByTestId('decision-reasons').locator('[data-rule="PS-02"]')).toContainText('سعر البيع');
        const form = page.getByTestId('publish-form');
        await form.getByLabel(/^الباركود/).fill('6221234567890');
        await form.getByLabel(/^سعر البيع/).fill('39');
        await form.getByLabel(/^سعر الجملة/).fill('31');
        await form.getByLabel(/^السعر الاسترشادي/).fill('45');
        await form.getByLabel(/^الاسم بالإنجليزي/).fill('Premium Shampoo 400ml');
        await shot('publish-form-filled');
        await page.getByTestId('publish-submit').click();
        await expect(page.getByText('اتحط في طابور Sanad: هيظهر في Sanad أول ما يسحب.')).toBeVisible();
        await expect(page.getByTestId('publish-history')).toContainText(code);
        // Q-07: the history says where Sanad stands for what was published (queued: nothing pulled it yet).
        await expect(page.getByTestId('publish-history')).toContainText('في انتظار سند');
        await shot('publish-done');

        const payload = JSON.parse(artisan(`tinker --execute='echo json_encode(App\\Models\\SanadOutbox::query()->latest("id")->get()->first(fn ($row) => ($row->payload["sku"] ?? null) === "${code}")->payload);'`).trim());
        expect([payload.item_kind, payload.cost_price, payload.sell_price, payload.wholesale_price, payload.guide_price, payload.barcode, payload.tax_rate]).toEqual(['finished_good', '18.4573', '39', '31', '45', '6221234567890', '14.00']);

        // A material price that rose past the limit needs the cost to be approved again.
        artisan(`tinker --execute='App\\Models\\Price::factory()->create(["trade_material_id" => App\\Models\\TradeMaterial::query()->where("trade_name", "Demo Cost Base ${code}")->value("id"), "amount" => "10.6", "quoted_on" => "2026-11-20", "confidence" => "L1_coa_batch", "source_id" => App\\Models\\Source::query()->where("title", "INV-2026-0042")->value("id")]);'`);
        await page.reload();
        await expect(page.getByTestId('publish-decision')).toHaveAttribute('data-state', 'blocked');
        await expect(page.getByTestId('decision-reasons').locator('[data-rule="PS-04"]')).toContainText('إعادة اعتماد');
        await expect(page.getByTestId('publish-submit')).toBeDisabled();
        await page.getByTestId('publish-decision').scrollIntoViewIfNeeded();
        await shot('publish-blocked-price-moved');
      });
    });
  }
}
