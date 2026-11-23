import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, pickRecord, setTheme, settle } from './support/kit';

/**
 * Review screenshots of J-09 (the optimizer answer saved as a draft version, and the provenance it carries) and N-08 (the EDA notification files:
 * the list with its readiness, one file part-filled, one whose document list is not defined, and the record modal). Arabic RTL, light and dark,
 * on a desktop and a 390px phone, at deviceScaleFactor 2. Data: TiersDemoSeeder (TEST-FIXTURE numbers), DisinfectantDemoSeeder and the TEST-FIXTURE
 * checklist (invented document names; the shipped data lists none). Saved to orchestration/evidence/J-09-N-08/. Every shot asserts RTL and no sideways scroll.
 */
const OUT = 'orchestration/evidence/J-09-N-08';

test.describe.configure({ mode: 'serial' });

const artisan = (command: string): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' });

test.beforeAll(() => {
  artisan('db:seed --class=TiersDemoSeeder --force');
  artisan('db:seed --class=DisinfectantDemoSeeder --force');
  artisan(`tinker --execute='if (! App\\Models\\Formula::query()->where("code", "DEMO-OPT-SHOT")->exists()) { App\\Models\\Formula::factory()->create(["tenant_id" => App\\Models\\Tenant::query()->value("id"), "code" => "DEMO-OPT-SHOT", "name" => "Demo optimized (screenshots)", "product_type" => "demo_shampoo"]); }'`);
});

test.beforeEach(() => {
  // Two documents recorded on the demo shampoo: one attached, one not applicable; the third is still missing.
  artisan(`tinker --execute='App\\Models\\EdaChecklistEntry::query()->delete(); $f = App\\Models\\Formula::query()->where("code", "DEMO-DISINF-SHAMPOO")->firstOrFail(); App\\Models\\EdaChecklistEntry::query()->create(["formula_id" => $f->id, "item_code" => "test_doc_a", "status" => "attached", "document_title" => "شهادة تحليل الدفعة", "document_reference" => "REF-2026-1"]); App\\Models\\EdaChecklistEntry::query()->create(["formula_id" => $f->id, "item_code" => "test_doc_b", "status" => "not_applicable", "note" => "المنتج مفيهوش الجزء ده"]);'`);
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        test.setTimeout(120_000);
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        // J-09: solve, then save the answer as a draft version.
        await page.goto('/tiers');
        await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-OPT-SHOT', /DEMO-OPT-SHOT/);
        await expect(page).toHaveURL(/\/tiers\?formula=/);
        await expect(page.getByTestId('tier-profile')).toBeVisible();
        await page.getByTestId('tier-switch').getByRole('button', { name: 'متوسط' }).click();
        await expect(page).toHaveURL(/tier=mid/);
        await expect(page.getByTestId('tier-profile')).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        if (await page.getByTestId('profile-empty').count()) {
          await page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first().fill('10');
          await pickRecord(page, page.getByRole('combobox', { name: /^مصدر الأرقام/ }), 'Demo tiers cost sheet');
          await page.getByTestId('profile-save').click();
          await expect(page.getByTestId('profile-empty')).toHaveCount(0);
        }
        await pickRecord(page, page.getByRole('combobox', { name: /^خامة الإكمال/ }), 'Zz Tier Water', /Zz Tier Water/);
        await page.getByTestId('solve').click();
        await expect(page.getByTestId('result-status')).toContainText('حل أمثل');
        await page.getByTestId('save-draft').scrollIntoViewIfNeeded();
        await shot('tiers-save-draft');

        await page.getByTestId('save-draft-button').click();
        await expect(page.getByTestId('draft-saved')).toHaveAttribute('data-status', 'draft');
        await shot('tiers-draft-saved');

        await page.getByTestId('draft-open').click();
        await expect(page.getByTestId('workflow-provenance')).toBeVisible();
        await page.getByTestId('workflow-provenance').scrollIntoViewIfNeeded();
        await shot('version-provenance');

        // N-08: the list, a part-filled file, a file whose list is not defined, and the record modal.
        await page.goto('/eda?search=DEMO-DISINF');
        await expect(page.getByTestId('eda-summary')).toBeVisible();
        await shot('eda-list');

        await page.getByText('DEMO-DISINF-SHAMPOO', { exact: true }).first().click();
        await expect(page.locator('[data-item]')).toHaveCount(3);
        await shot('eda-file');

        await page.locator('[data-item="test_doc_c"]').getByTestId('item-edit').click();
        await expect(page.getByTestId('item-title')).toBeVisible();
        await page.getByTestId('item-save').click();
        await expect(page.getByText('اكتب إيه هو المستند المرفق.')).toBeVisible();
        await shot('eda-record-error');
        await page.keyboard.press('Escape');

        await page.goto('/eda?search=DEMO-DISINFECTANT');
        await page.getByText('DEMO-DISINFECTANT', { exact: true }).first().click();
        await expect(page.getByTestId('eda-not-sourced')).toBeVisible();
        await shot('eda-not-sourced');
      });
    });
  }
}
