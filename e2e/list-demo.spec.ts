import { execSync } from 'node:child_process';
import { expect, test } from '@playwright/test';

// The playground exists only in local/testing. Rows are deterministic: "E2E Material 01..24"
// (every 3rd active, every 5th a mixture, 10 per page), seeded by ListDemoSeeder.
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  execSync('php artisan db:seed --class=ListDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
});

const rows = (page: import('@playwright/test').Page) => page.locator('tbody tr');

test('search, select filter and sort live in the URL and survive a reload', async ({ page }) => {
  await page.goto('/__dev/list-demo?search=E2E');
  await expect(page.getByRole('heading', { level: 1, name: 'تجربة مكونات القوائم' })).toBeVisible();
  await expect(rows(page)).toHaveCount(10);

  // Search narrows the server result and writes ?search=
  await page.getByRole('searchbox').fill('Material 07');
  await expect(page).toHaveURL(/search=Material\+07|search=Material%2007/);
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('E2E Material 07');

  // Select filter (status) writes ?status=
  await page.getByRole('searchbox').fill('E2E');
  await expect(rows(page)).toHaveCount(10);
  await page.locator('[data-filter-controls]').getByRole('button', { name: 'الحالة' }).click();
  await page.getByRole('menuitemradio', { name: 'نشط' }).click();
  await expect(page).toHaveURL(/status=active/);
  await expect(rows(page)).toHaveCount(8); // 3,6,...,24
  for (const row of await rows(page).all()) {
    await expect(row).toContainText('نشط');
  }

  // Sort descending by name through the sort control
  await page.getByRole('button', { name: 'تنازلي' }).or(page.getByRole('button', { name: 'تصاعدي' })).click();
  await expect(page).toHaveURL(/dir=desc/);
  await expect(rows(page).first()).toContainText('E2E Material 24');

  // A reload keeps search, filter and sort (the URL is the state)
  await page.reload();
  await expect(page.getByRole('searchbox')).toHaveValue('E2E');
  await expect(page).toHaveURL(/status=active/);
  await expect(rows(page)).toHaveCount(8);
  await expect(rows(page).first()).toContainText('E2E Material 24');
  await expect(page.locator('[data-filter-count]')).toHaveText('1'); // the kit counts filters; the search text is not one
});

test('clear all resets search and filters but keeps the sort', async ({ page }) => {
  await page.goto('/__dev/list-demo?search=E2E&status=active&sort=trade_name&dir=desc&is_mixture=1');
  await expect(rows(page)).toHaveCount(1); // only 15 is both active (every 3rd) and a mixture (every 5th)
  // With filter chips showing, the kit's own "Clear all" (chips row) clears the filters and the search.
  await page.getByRole('button', { name: 'مسح الكل', exact: true }).click();
  await expect(page).not.toHaveURL(/status=|search=|is_mixture=/);
  await expect(page).toHaveURL(/dir=desc/);
  await expect(page.getByRole('searchbox')).toHaveValue('');
  await expect(rows(page)).toHaveCount(10);
});

test('page two keeps filters, and a filter change returns to page one', async ({ page }) => {
  await page.goto('/__dev/list-demo?search=E2E');
  await page.getByRole('button', { name: /2/ }).filter({ hasText: /^2$/ }).first().click();
  await expect(page).toHaveURL(/page=2/);
  await expect(rows(page).first()).toContainText('E2E Material 11');

  await page.getByRole('searchbox').fill('E2E Material 1');
  await expect(page).not.toHaveURL(/page=2/);
});

test('filtered-empty state offers a way out', async ({ page }) => {
  await page.goto('/__dev/list-demo?search=zzzz-no-match');
  await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر')).toBeVisible();
  // The kit's Clear all is on the bar when only search text is typed.
  await page.getByRole('button', { name: 'مسح الكل', exact: true }).click();
  await expect(rows(page)).not.toHaveCount(0);
});

test('entity selects: local combobox and server-searched typeahead', async ({ page }) => {
  // ?search=E2E scopes the preloaded options to the fixture rows, whatever else the database holds.
  await page.goto('/__dev/list-demo?search=E2E');

  const selected = page.getByTestId('selected-values');
  await expect(selected).toContainText('—');

  // Local options
  const local = page.getByRole('combobox', { name: 'اختيار من قائمة محلية' });
  await local.click();
  await local.fill('E2E Material 03');
  await page.getByRole('option', { name: 'E2E Material 03' }).click();
  await expect(selected).not.toContainText('— / —');

  // Remote search: the request carries ?q= and the chosen id lands in the model
  const remote = page.getByRole('combobox', { name: 'اختيار بالبحث من السيرفر' });
  const request = page.waitForRequest((r) => r.url().includes('/__dev/list-demo/materials?q=E2E+Material+17') || r.url().includes('q=E2E%20Material%2017'));
  await remote.fill('E2E Material 17');
  await request;
  await page.getByRole('option', { name: /E2E Material 17/ }).click();
  await expect(remote).toHaveValue('E2E Material 17');
  await expect(selected).toContainText(/[0-9a-f]{8}-[0-9a-f-]{27}\s*\/\s*[0-9a-f]{8}-/);

  // Emptying the box clears the choice
  await remote.fill('');
  await expect(selected).toContainText(/\/\s*—/);
});

test('list controls fit a phone without horizontal scroll', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/__dev/list-demo?search=E2E');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  await expect(page.getByRole('searchbox')).toBeVisible();
});
