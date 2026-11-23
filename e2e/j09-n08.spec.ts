import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { expectRtlAndFits, pickRecord } from './support/kit';

/**
 * J-09: an optimized answer saved as a draft version with where it came from; it never approves itself (the lab workflow's gate stays).
 * Water 1 EGP/kg (0 % active) and the surfactant at 110 EGP/kg (50 % active), active matter >= 10 %: 20 % surfactant + 80 % water (TiersDemoSeeder, TEST-FIXTURE).
 * N-08: the EDA notification file assistant. The shipped data lists no document (the project sources do not), so a TEST-FIXTURE list of three invented
 * documents (apps/api/tests/fixtures/eda-checklist.json) is switched on for the e2e server only, for the cosmetic groups; the disinfectant list stays undefined.
 */
test.describe.configure({ mode: 'serial' });

const artisan = (command: string): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' });

test.beforeAll(() => {
  artisan('db:seed --class=TiersDemoSeeder --force');
  artisan('db:seed --class=DisinfectantDemoSeeder --force');
  artisan(`tinker --execute='if (! App\\Models\\Formula::query()->where("code", "DEMO-OPT")->exists()) { App\\Models\\Formula::factory()->create(["tenant_id" => App\\Models\\Tenant::query()->value("id"), "code" => "DEMO-OPT", "name" => "Demo optimized", "product_type" => "demo_shampoo"]); }'`);
  artisan(`tinker --execute='App\\Models\\EdaChecklistEntry::query()->delete();'`);
});

test.describe('J-09 save the optimizer answer as a draft version', () => {
  test('the answer is saved as a draft with its provenance, the same inputs give the same hash, and it cannot be approved without a lab trial', async ({ page }) => {
    await page.goto('/tiers');
    await pickRecord(page, page.getByRole('combobox', { name: /^المنتج/ }), 'DEMO-OPT', /DEMO-OPT/);
    await expect(page).toHaveURL(/\/tiers\?formula=/);
    await expect(page.getByTestId('tier-profile')).toBeVisible();
    await page.getByTestId('tier-switch').getByRole('button', { name: 'متوسط' }).click();
    await expect(page).toHaveURL(/tier=mid/);
    await expect(page.getByTestId('tier-profile')).toBeVisible();

    await page.getByLabel(/^الحد الأدنى للمادة الفعالة/).first().fill('10');
    await pickRecord(page, page.getByRole('combobox', { name: /^مصدر الأرقام/ }), 'Demo tiers cost sheet');
    await page.getByTestId('profile-save').click();
    await expect(page.getByTestId('profile-empty')).toHaveCount(0);
    await pickRecord(page, page.getByRole('combobox', { name: /^خامة الإكمال/ }), 'Zz Tier Water', /Zz Tier Water/);

    // No answer yet: nothing to save.
    await expect(page.getByTestId('save-draft')).toHaveCount(0);
    await page.getByTestId('solve').click();
    await expect(page.getByTestId('result-status')).toContainText('حل أمثل');
    await expect(page.getByTestId('save-draft')).toBeVisible();

    await page.getByTestId('save-draft-button').click();
    const saved = page.getByTestId('draft-saved');
    await expect(saved).toBeVisible();
    await expect(saved).toHaveAttribute('data-status', 'draft');
    const firstHash = await saved.getAttribute('data-hash');
    const firstNumber = Number(await saved.getAttribute('data-version'));
    expect(firstHash).toMatch(/^[0-9a-f]{64}$/);
    await expect(page.getByTestId('save-draft-button')).toBeDisabled();

    // The same inputs again: a new version with the same hash of its lines.
    await page.getByTestId('solve').click();
    await expect(page.getByTestId('result-status')).toContainText('حل أمثل');
    await expect(page.getByTestId('draft-saved')).toHaveCount(0);
    await page.getByTestId('save-draft-button').click();
    await expect(page.getByTestId('draft-saved')).toHaveAttribute('data-version', String(firstNumber + 1));
    await expect(page.getByTestId('draft-saved')).toHaveAttribute('data-hash', firstHash as string);

    // The version opens as a draft that says where it came from and has no approve button.
    await page.getByTestId('draft-open').click();
    await expect(page).toHaveURL(new RegExp(`/versions/${firstNumber + 1}/edit`));
    const panel = page.getByTestId('workflow-panel');
    await expect(panel).toHaveAttribute('data-status', 'draft');
    await expect(page.getByTestId('workflow-provenance')).toHaveAttribute('data-origin', 'optimizer');
    await expect(page.getByTestId('workflow-provenance')).toContainText('المُحسِّن');
    await expect(page.getByTestId('workflow-provenance')).toContainText('highs');
    await expect(page.getByTestId('workflow-approve')).toHaveCount(0);

    // In the lab it still cannot be approved: no completed trial holds the required measurements.
    await page.getByTestId('workflow-send').click();
    await page.getByTestId('workflow-confirm').click();
    await expect(panel).toHaveAttribute('data-status', 'lab_testing');
    await expect(page.getByTestId('workflow-approve')).toBeDisabled();
  });
});

test.describe('N-08 EDA notification file', () => {
  test('the list shows each EDA product with its readiness, a product whose list is not defined is never ready, and filters live in the URL', async ({ page }) => {
    await page.goto('/eda');
    await expectRtlAndFits(page);
    await expect(page.getByRole('heading', { level: 1, name: 'ملفات إخطار هيئة الدواء' })).toBeVisible();
    await expect(page.getByTestId('eda-summary')).toBeVisible();

    await page.goto('/eda?search=DEMO-DISINF');
    const shampoo = page.locator('tbody tr', { hasText: 'DEMO-DISINF-SHAMPOO' });
    const alcohol = page.locator('tbody tr', { hasText: 'DEMO-DISINFECTANT' });
    await expect(shampoo).toContainText('ناقص');
    await expect(alcohol).toContainText('قايمة المستندات لسه مش متحددة');

    await page.goto('/eda?search=DEMO-DISINF&group=disinfectants');
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr').first()).toContainText('DEMO-DISINFECTANT');
  });

  test('an undefined list says so and records nothing; a defined list is filled item by item until the file is ready', async ({ page }) => {
    await page.goto('/eda?search=DEMO-DISINFECTANT');
    await page.locator('tbody tr', { hasText: 'DEMO-DISINFECTANT' }).click();
    await expect(page.getByTestId('eda-show')).toHaveAttribute('data-state', 'items_not_sourced');
    await expect(page.getByTestId('eda-not-sourced')).toBeVisible();
    await expect(page.getByTestId('eda-checklist')).toHaveCount(0);

    await page.goto('/eda?search=DEMO-DISINF-SHAMPOO');
    await page.locator('tbody tr', { hasText: 'DEMO-DISINF-SHAMPOO' }).click();
    await expect(page.getByTestId('eda-show')).toHaveAttribute('data-state', 'incomplete');
    await expect(page.locator('[data-item]')).toHaveCount(3);

    const first = page.locator('[data-item="test_doc_a"]');
    // An attached document needs its title.
    await first.getByTestId('item-edit').click();
    await page.getByTestId('item-save').click();
    await expect(page.getByText('اكتب إيه هو المستند المرفق.')).toBeVisible();
    await page.getByTestId('item-title').fill('شهادة تحليل');
    await page.getByTestId('item-reference').fill('REF-2026-1');
    await page.getByTestId('item-save').click();
    await expect(first).toHaveAttribute('data-status', 'attached');
    await expect(first).toContainText('شهادة تحليل');

    // Not applicable needs the reason.
    const second = page.locator('[data-item="test_doc_b"]');
    await second.getByTestId('item-edit').click();
    await page.getByTestId('item-status').getByRole('button', { name: 'لا ينطبق' }).click();
    await page.getByTestId('item-save').click();
    await expect(page.getByText('اكتب ليه المستند ده مش بيخص المنتج.')).toBeVisible();
    await page.getByTestId('item-note').fill('المنتج مفيهوش الجزء ده');
    await page.getByTestId('item-save').click();
    await expect(second).toHaveAttribute('data-status', 'not_applicable');
    await expect(page.getByTestId('eda-progress')).toContainText('1 من 2');
    await expect(page.getByTestId('eda-show')).toHaveAttribute('data-state', 'incomplete');

    const third = page.locator('[data-item="test_doc_c"]');
    await third.getByTestId('item-edit').click();
    await page.getByTestId('item-title').fill('خطاب');
    await page.getByTestId('item-save').click();
    await expect(page.getByTestId('eda-show')).toHaveAttribute('data-state', 'ready');

    // Back to missing: the file is incomplete again.
    await third.getByTestId('item-edit').click();
    await page.getByTestId('item-status').getByRole('button', { name: 'ناقص' }).click();
    await page.getByTestId('item-save').click();
    await expect(page.getByTestId('eda-show')).toHaveAttribute('data-state', 'incomplete');
    await expect(third).toHaveAttribute('data-status', 'missing');
  });
});
