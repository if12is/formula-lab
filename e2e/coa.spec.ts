import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { expectRtlAndFits, pickRecord } from './support/kit';

/**
 * P-05: the COA review screen. A scripted stand-in for the model (CoaDemoSeeder, TEST-FIXTURE: made-up certificates in tests/fixtures/coa) has read two
 * certificates through the real pipeline. A person decides every value: nothing is a material property before the confirmation, and a value
 * becomes L1 only after it. The model is never called here (no network, no key).
 */
test.describe.configure({ mode: 'serial' });

const artisan = (command: string): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' });
const readDemo = (material: string): void => {
  artisan(`tinker --execute='app(Database\\Seeders\\CoaDemoSeeder::class)->readDemo("${material}");'`);
};

const FUNCTIONAL = 'E2E COA Functional';
const REJECTED = 'E2E COA Rejected';

test.beforeAll(() => {
  artisan('db:seed --class=CoaDemoSeeder --force');
  readDemo(FUNCTIONAL);
  readDemo(REJECTED);
});

/** Opens the review of the certificate of this material and lot, from the list. */
async function openReview(page: Page, material: string, lot: string): Promise<void> {
  await page.goto(`/coa?search=${encodeURIComponent(material)}`);
  await expect(page.getByTestId('coa-list')).toBeVisible();
  await page.getByRole('row').filter({ hasText: lot }).getByText(lot).click();
  await expect(page.getByTestId('coa-review')).toBeVisible();
}

const decide = async (page: Page, field: number, decision: 'تأكيد' | 'رفض'): Promise<void> => {
  await page.getByTestId(`field-decision-${field}`).getByRole('button', { name: decision, exact: true }).click();
};

test('the list shows the certificates and its search and status filter live in the URL', async ({ page }) => {
  await page.goto('/coa');
  await expect(page.getByTestId('coa-list')).toBeVisible();
  await expectRtlAndFits(page);

  await page.getByRole('searchbox').fill(FUNCTIONAL);
  await expect(page).toHaveURL(/search=/);
  await expect(page.getByRole('row').filter({ hasText: 'DEMO-LOT-01' })).toHaveCount(1);
  await expect(page.getByRole('row').filter({ hasText: 'DEMO-LOT-02' })).toHaveCount(1);
  await expect(page.getByRole('row').filter({ hasText: 'مستنية مراجعة' })).toHaveCount(2);

  await page.goto(`/coa?search=${encodeURIComponent(FUNCTIONAL)}&status=confirmed`);
  await expect(page.getByTestId('coa-list')).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'DEMO-LOT' })).toHaveCount(0);
  await page.getByRole('button', { name: 'مسح كل الفلاتر' }).first().click();
  await expect(page).not.toHaveURL(/status=/);
});

test('every value is decided by a person, nothing is saved before the confirmation, and a confirmed value is L1 with its lot', async ({ page }) => {
  await openReview(page, FUNCTIONAL, 'DEMO-LOT-01');
  await expectRtlAndFits(page);

  // A draft: the screen says it is unconfirmed, the original is beside it, and the certificate's name, quote and method are shown.
  await expect(page.getByTestId('coa-unconfirmed')).toBeVisible();
  await expect(page.getByTestId('coa-original-pdf')).toBeVisible();
  await expect(page.getByTestId('coa-lot')).toHaveValue('DEMO-LOT-01');
  await expect(page.getByTestId('coa-field-1').getByTestId('field-quote')).toContainText('1.2610 g/mL');
  await expect(page.getByTestId('coa-field-1').getByTestId('field-method')).toContainText('ASTM D1298');

  // What the checks found: the number the model made up is gone, the ambiguous 1,412 is not guessed, the unknown test has no property.
  await expect(page.getByTestId('coa-field-5')).toHaveAttribute('data-blocking', 'true');
  await expect(page.getByTestId('coa-field-5').getByTestId('field-issues')).toContainText('الرقم مش في الشهادة');
  await expect(page.getByTestId('coa-field-4').getByTestId('field-issues')).toContainText('مش رقم واحد واضح');
  await expect(page.getByTestId('coa-field-6').getByTestId('field-issues')).toContainText('خاصية غير معروفة');
  await expect(page.getByTestId('field-value-5')).toHaveValue('');

  // Nothing decided: the confirm button is off and says why.
  await expect(page.getByTestId('coa-confirm')).toBeDisabled();
  await expect(page.getByTestId('coa-confirm-hint')).toBeVisible();
  await expect(page.getByTestId('coa-counts')).toContainText('6 من غير قرار');

  // Nothing is a property of the material yet.
  const materialHref = await page.getByRole('link', { name: 'فتح الخامة' }).first().getAttribute('href');
  await page.goto(`${materialHref}`);
  await expect(page.getByRole('heading', { name: FUNCTIONAL, level: 1 })).toBeVisible();
  await expect(page.getByRole('cell', { name: '1.261', exact: true })).toHaveCount(0);
  await expect(page.getByText('DEMO-LOT-01')).toHaveCount(0);
  await page.goBack();

  await decide(page, 1, 'تأكيد');
  await decide(page, 2, 'تأكيد');
  await decide(page, 3, 'تأكيد');
  // The person types the number from the original, then confirms it.
  await page.getByTestId('field-value-4').fill('1412');
  await decide(page, 4, 'تأكيد');
  await decide(page, 5, 'رفض');
  await decide(page, 6, 'رفض');
  await expect(page.getByTestId('coa-counts')).toContainText('4 مؤكدة، 2 مرفوضة، 0 من غير قرار');
  await expect(page.getByTestId('coa-confirm')).toBeEnabled();
  await page.getByTestId('coa-confirm').click();

  await expect(page.getByTestId('coa-confirmed')).toBeVisible();
  await expect(page.getByTestId('coa-review')).toHaveAttribute('data-status', 'confirmed');
  await expect(page.getByTestId('coa-field-4').getByTestId('field-decision')).toContainText('عدّلتها');
  await expect(page.getByTestId('coa-field-5').getByTestId('field-decision')).toContainText('اترفضت');
  await expect(page.getByTestId('coa-actions')).toHaveCount(0);
  await expect(page.getByTestId('coa-history')).toContainText('مؤكدة');

  // Now the values are properties of the material, at L1, with the lot.
  await page.goto(`${materialHref}`);
  await expect(page.getByRole('cell', { name: '1.261', exact: true }).first()).toBeVisible();
  await expect(page.getByRole('cell', { name: '1,412', exact: true }).first()).toBeVisible();
  await expect(page.getByText('DEMO-LOT-01').first()).toBeVisible();
  await expect(page.getByText('L1', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('7.5')).toHaveCount(0);
});

test('a scan has no text layer to check against, and rejecting the certificate saves nothing and keeps the reason', async ({ page }) => {
  await openReview(page, REJECTED, 'DEMO-LOT-02');

  await expect(page.getByTestId('coa-unverified')).toBeVisible();
  await expect(page.getByTestId('coa-original-image')).toBeVisible();
  await expect(page.getByTestId('coa-field-1').getByTestId('field-issues')).toContainText('مش متحقق منه');

  await page.getByTestId('coa-reject-all').click();
  await page.getByTestId('coa-reject-submit').click();
  await expect(page.getByText('لازم تكتب السبب').or(page.getByText(/السبب.*مطلوب|مطلوب/)).first()).toBeVisible();
  await page.getByTestId('coa-reject-reason').fill('الشهادة لخامة تانية');
  await page.getByTestId('coa-reject-submit').click();

  await expect(page.getByTestId('coa-rejected')).toBeVisible();
  await expect(page.getByTestId('coa-history')).toContainText('الشهادة لخامة تانية');
  await expect(page.getByTestId('coa-actions')).toHaveCount(0);
  await expect(page.getByTestId('coa-field-1').getByTestId('field-decision')).toContainText('من غير قرار');
});

test('the upload form needs a material and a file before it can read, and tells the person what is sent', async ({ page }) => {
  await page.goto('/coa');
  await page.getByTestId('coa-upload-open').click();
  await expect(page.getByTestId('coa-upload-submit')).toBeDisabled();
  await expect(page.getByText(/بيتبعت لخدمة Claude/)).toBeVisible();

  await pickRecord(page, page.getByRole('combobox', { name: /^الخامة/ }), 'Demo Glycerin', /Demo Glycerin \(COA\)/);
  await expect(page.getByTestId('coa-upload-submit')).toBeDisabled();
  await page.getByTestId('coa-file-input').locator('input[type="file"]').setInputFiles('apps/api/tests/fixtures/coa/sample-coa.pdf');
  await expect(page.getByTestId('coa-upload-submit')).toBeEnabled();
});
