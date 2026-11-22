import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { CONFIDENCE_TDS, expectNoHorizontalScroll, expectRtlAndFits, pickOption, pickRecord, setTheme, settle } from './support/kit';

/**
 * Per-merchant price lists (contract section 8) end to end: create a list, price products by a margin rule and by an agreed price
 * (each with the engine's margin, an explain tree and a below-cost warning), cost a product, assign merchants from the Sanad
 * customers cache, publish (the unpublished-product error, then the `price_list_upsert` outbox change with the right payload),
 * and Sanad's verdicts (applied, failed, conflict). The margin rule comes from the server's PRICING_RULES (+25 % on cost).
 *
 * Hand-computed (TEST-FIXTURE numbers): unit cost 32.00, rule +25 % => price 40.00, margin 8.00 = 20.0 % of price, 25.0 % of cost.
 * Agreed price 40 against a unit cost of 50.00 => margin -10.00 = -25.0 % of price, -20.0 % of cost: below cost.
 * A product costed at 20.00 by the same rule => 25.00. Review screenshots (Arabic RTL, light + dark, desktop + 390px phone) go to
 * orchestration/evidence/PRICE-LISTS/. One worker runs the whole file in order: the tests share the demo products and lists.
 */
const OUT = 'orchestration/evidence/PRICE-LISTS';
const LIST = 'قائمة العرض';
const NEW_LIST = 'تجار القاهرة';

test.describe.configure({ mode: 'serial' });

const artisan = (command: string): string => execSync(`php artisan ${command}`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' });

function seedDemo(): void {
  artisan('db:seed --class=PriceListDemoSeeder --force');
  cleanTestLists();
}

function seedEvidenceList(): void {
  artisan('db:seed --class=PriceListEvidenceSeeder --force');
}

/** Removes the lists a spec created (and their outbox rows, assignments and the customers' Sanad pointer). */
function cleanTestLists(): void {
  artisan(`tinker --execute='App\\Models\\SanadOutbox::query()->whereIn("kind", ["price_list_upsert"])->delete(); App\\Models\\PriceList::query()->get()->each->delete(); App\\Models\\SanadCustomer::query()->where("uuid", "like", "c0de0000%")->update(["price_list_uuid" => null]);'`);
  artisan('db:seed --class=PriceListDemoSeeder --force');
}

/** The last queued price list payload as JSON (what Sanad would pull). */
function lastOutbox(): { id: string; payload: { uuid: string; name: string; currency: string; is_active: boolean; items: { product_uuid: string; price: string | null }[]; assign_customers: string[]; unassign_customers: string[] } } {
  const out = artisan(`tinker --execute='$r = App\\Models\\SanadOutbox::query()->where("kind", "price_list_upsert")->orderByDesc("id")->first(); echo json_encode($r ? ["id" => $r->id, "payload" => $r->payload] : null);'`);
  return JSON.parse(out.trim());
}

function outboxCount(): number {
  return Number(artisan(`tinker --execute='echo App\\Models\\SanadOutbox::query()->where("kind", "price_list_upsert")->count();'`).trim());
}

/** Plays Sanad's acknowledgement of an outbox row through the real ack action. */
function ack(id: string, status: string, detail: string): void {
  artisan(`tinker --execute='$d = App\\Models\\SanadOutbox::query()->find("${id}")->device; app(App\\Actions\\Sanad\\AcknowledgeSanadOutboxItemAction::class)($d, ["id" => "${id}", "status" => "${status}", ${detail}]);'`);
}

async function openList(page: Page, name: string, tab = ''): Promise<void> {
  await page.goto(`/price-lists?search=${encodeURIComponent(name)}`);
  await page.getByText(name, { exact: true }).first().click();
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
  if (tab) {
    await page.getByRole('link', { name: new RegExp(tab) }).click();
  }
}

const dialog = (page: Page) => page.getByRole('dialog');

async function pickProduct(page: Page, code: string): Promise<void> {
  await pickRecord(page, dialog(page).getByRole('combobox', { name: /^المنتج/ }), code, new RegExp(code));
}

async function fillAgreedPrice(page: Page, price: string): Promise<void> {
  await dialog(page).getByRole('button', { name: 'سعر متفق عليه' }).click();
  await dialog(page).getByLabel(/^السعر المتفق عليه/).fill(price);
  await pickRecord(page, dialog(page).getByRole('combobox', { name: /^مصدر السعر/ }), 'Demo merchant agreement');
  await pickOption(page, dialog(page).getByRole('combobox', { name: /^درجة ثقة السعر/ }), CONFIDENCE_TDS);
}

async function submitLine(page: Page): Promise<void> {
  await page.getByTestId('line-submit').click();
  await expect(dialog(page)).toHaveCount(0);
}

const row = (page: Page, code: string) => page.locator(`[data-line="${code}"]`);

/** Closes the toasts a previous action left (they sit over the bottom of a dialog at this window size). */
async function dismissToasts(page: Page): Promise<void> {
  const close = page.getByRole('region', { name: /الإشعارات/ }).getByRole('button', { name: 'إغلاق' });
  for (let attempt = 0; attempt < 5 && (await close.count()) > 0; attempt++) {
    // A toast that times out while it is being closed is fine: the goal is that none is left.
    await close.first().click({ timeout: 2000 }).catch(() => undefined);
  }
}

/** A row action (edit, remove): the kit shows it when the row is hovered or focused, so hover first like a person does. */
async function rowAction(page: Page, code: string, action: 'edit-line' | 'remove-line'): Promise<void> {
  await dismissToasts(page);
  await row(page, code).hover();
  await page.getByTestId(`${action}-${code}`).click();
}

test.beforeAll(() => {
  seedDemo();
});

test.describe('price list behaviour', () => {
  test('create a list, price products by a rule and an agreed price, see margins and the below-cost warning', async ({ page }) => {
    await page.goto('/price-lists');
    await expect(page.getByRole('heading', { level: 1, name: 'قوائم الأسعار' })).toBeVisible();
    await expect(page.getByText('لا توجد قوائم أسعار بعد')).toBeVisible();

    await page.getByTestId('new-list').first().click();
    await page.getByTestId('list-name').fill(NEW_LIST);
    await page.getByTestId('list-create').click();
    await expect(page.getByRole('heading', { level: 1, name: NEW_LIST })).toBeVisible();
    await expect(page.getByTestId('sync-status')).toContainText('لم تُنشر');
    await expect(page.getByText('لا توجد منتجات في القائمة')).toBeVisible();

    // A rule line: 32.00 + 25 % = 40.00, margin 8.00 (20.0 % of price, 25.0 % of cost), computed by the engine before saving.
    await page.getByTestId('add-line').click();
    await pickProduct(page, 'PL-SHAMPOO');
    await pickOption(page, dialog(page).getByRole('combobox', { name: /^قاعدة الهامش/ }), 'تجار الجملة');
    await expect(page.getByTestId('rule-card')).toContainText('25');
    await expect(page.getByTestId('preview-price')).toContainText('40.00');
    await expect(page.getByTestId('preview-cost')).toContainText('32.00');
    await expect(page.getByTestId('preview-margin')).toContainText('8.00');
    await expect(page.getByTestId('line-preview')).toContainText('20.0');
    await expect(page.getByTestId('line-preview')).toHaveAttribute('data-state', 'ok');
    await submitLine(page);
    await expect(page.locator('[data-price="PL-SHAMPOO"]')).toContainText('40.00');
    await expect(page.locator('[data-cost="PL-SHAMPOO"]')).toContainText('32.00');
    await expect(page.locator('[data-margin="PL-SHAMPOO"]')).toContainText('8.00');
    await expect(page.locator('[data-status="PL-SHAMPOO"]')).toHaveAttribute('data-state', 'ok');

    // An agreed price below cost: 40 against 50.00 => margin -10.00, and a warning (still allowed to save).
    await page.getByTestId('add-line').click();
    await pickProduct(page, 'PL-SOAP');
    await fillAgreedPrice(page, '40');
    await expect(page.getByTestId('line-preview')).toHaveAttribute('data-state', 'below_cost');
    await expect(page.getByTestId('preview-margin')).toContainText('-10.00');
    await expect(page.getByTestId('below-cost')).toBeVisible();
    await submitLine(page);
    await expect(page.locator('[data-status="PL-SOAP"]')).toHaveAttribute('data-state', 'below_cost');
    await expect(page.getByTestId('below-cost-summary')).toBeVisible();

    // A product the engine cannot price: no unit cost yet. It says what is missing, never 0.
    await page.getByTestId('add-line').click();
    await pickProduct(page, 'PL-FLOOR');
    await pickOption(page, dialog(page).getByRole('combobox', { name: /^قاعدة الهامش/ }), 'تجار الجملة');
    await expect(page.getByTestId('line-preview')).toHaveAttribute('data-state', 'missing');
    await expect(page.getByTestId('missing')).toContainText('تكلفة الوحدة');
    await submitLine(page);
    await expect(page.locator('[data-status="PL-FLOOR"]')).toHaveAttribute('data-state', 'missing');
    await expect(page.locator('[data-price="PL-FLOOR"]')).not.toContainText('0.00');
    await expect(row(page, 'PL-FLOOR').getByTestId('not-published')).toBeVisible();
  });

  test('the price opens the engine calculation tree with the cost and the rule source', async ({ page }) => {
    await openList(page, NEW_LIST);
    await page.locator('[data-price="PL-SHAMPOO"]').getByTestId('explain-trigger').click();
    const panel = page.getByTestId('explain-panel');
    await expect(panel).toBeVisible();
    await expect(panel).toContainText('40.00');
    await expect(panel).toContainText('Unit cost');
    await expect(panel).toContainText('Demo cost sheet');
    await expect(panel).toContainText('قيمة اختبار لعرض الشاشة');
    await expect(panel).toContainText('32');
    await expect(panel).toContainText('25');
  });

  test('a unit cost is recorded with its source; the line is then priced: 20.00 + 25 % = 25.00', async ({ page }) => {
    await openList(page, NEW_LIST);
    await page.getByTestId('edit-cost-PL-FLOOR').click();
    await page.getByLabel(/^تكلفة الوحدة/).fill('20');
    await pickRecord(page, dialog(page).getByRole('combobox', { name: /^مصدر التكلفة/ }), 'Demo cost sheet');
    await pickOption(page, dialog(page).getByRole('combobox', { name: /^درجة ثقة التكلفة/ }), CONFIDENCE_TDS);
    await page.getByTestId('cost-submit').click();
    await expect(dialog(page)).toHaveCount(0);
    await expect(page.locator('[data-price="PL-FLOOR"]')).toContainText('25.00');
    await expect(page.locator('[data-status="PL-FLOOR"]')).toHaveAttribute('data-state', 'ok');
  });

  test('publishing a list that holds an unpublished product says which one; removing it lets the list go', async ({ page }) => {
    await openList(page, NEW_LIST);
    await page.getByTestId('publish').click();
    await expect(page.getByText(/PL-FLOOR/).first()).toBeVisible();
    await expect(page.getByText('لم تُنشر في سند بعد').or(page.getByText(/لم تُنشر في سند/)).first()).toBeVisible();
    expect(outboxCount()).toBe(0);

    await rowAction(page, 'PL-FLOOR', 'remove-line');
    await page.getByRole('alertdialog').getByRole('button', { name: 'حذف من القائمة' }).click();
    await expect(row(page, 'PL-FLOOR')).toHaveCount(0);
  });

  test('merchants come from the Sanad customers cache: searchable, active only, one list each', async ({ page }) => {
    await openList(page, NEW_LIST, 'التجار');
    await expect(page.getByText('لا يوجد تجار في القائمة')).toBeVisible();

    const select = page.getByTestId('add-customer').getByRole('combobox');
    await select.fill('الجملة');
    await expect(page.getByRole('option', { name: /تاجر الجملة - القاهرة/ })).toBeVisible();
    await select.fill('الأمل');
    await expect(page.getByRole('option', { name: /صيدلية الأمل/ })).toHaveCount(0);

    await pickRecord(page, select, 'الجملة', /تاجر الجملة - القاهرة/);
    await page.getByTestId('assign-customer').click();
    await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]')).toBeVisible();
    await pickRecord(page, select, 'Alexandria', /مخازن الإسكندرية/);
    await page.getByTestId('assign-customer').click();
    await expect(page.locator('[data-customer="مخازن الإسكندرية"]')).toBeVisible();

    // Where Sanad has each customer today: none on this list until the list is published.
    await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]').locator('xpath=ancestor::tr | ancestor::li | ancestor::div[contains(@class,"row")]').first()).toBeVisible();
    await expect(page.locator('[data-in-sanad="none"]').first()).toBeVisible();

    // Filters live in the URL.
    await page.getByRole('searchbox').or(page.getByPlaceholder('ابحث باسم التاجر أو كوده')).first().fill('الإسكندرية');
    await expect(page).toHaveURL(/search=/);
    await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]')).toHaveCount(0);
    await expect(page.locator('[data-customer="مخازن الإسكندرية"]')).toBeVisible();
    await page.reload();
    await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]')).toHaveCount(0);
    await page.getByRole('button', { name: /مسح الكل|مسح/ }).first().click();
    await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]')).toBeVisible();
  });

  test('publish queues one price_list_upsert with the engine prices and the merchants; republish keeps the list uuid; idempotent', async ({ page }) => {
    await openList(page, NEW_LIST);
    await page.getByTestId('publish').click();
    await expect(page.getByTestId('sync-status')).toContainText('بانتظار سند');
    expect(outboxCount()).toBe(1);

    const first = lastOutbox();
    expect(first.payload.name).toBe(NEW_LIST);
    expect(first.payload.currency).toBe('EGP');
    expect(first.payload.is_active).toBe(true);
    expect(first.payload.items.map((item) => item.price).sort()).toEqual(['40.00', '40.00']);
    expect(first.payload.assign_customers).toHaveLength(2);
    expect(first.payload.unassign_customers).toEqual([]);

    // Pressing publish again with nothing changed sends nothing new.
    await page.getByTestId('publish').click();
    await expect(page.getByTestId('sync-status')).toContainText('بانتظار سند');
    expect(outboxCount()).toBe(1);

    // A change, then a republish: the same list uuid, a new row.
    await rowAction(page, 'PL-SOAP', 'edit-line');
    await expect(dialog(page).getByTestId('line-product')).toContainText('PL-SOAP');
    await dialog(page).getByLabel(/^السعر المتفق عليه/).fill('52.5');
    await expect(page.getByTestId('preview-margin')).toContainText('2.50');
    await submitLine(page);
    await expect(page.getByTestId('changed-since-publish')).toBeVisible();
    await page.getByTestId('publish').click();
    await expect(page.getByTestId('changed-since-publish')).toHaveCount(0);
    expect(outboxCount()).toBe(2);
    const second = lastOutbox();
    expect(second.payload.uuid).toBe(first.payload.uuid);
    expect(second.payload.items.map((item) => item.price).sort()).toEqual(['40.00', '52.50']);
  });

  test('Sanad verdicts reach the list: failed (with code and retry), applied, conflict', async ({ page }) => {
    await openList(page, NEW_LIST);
    const { id } = lastOutbox();

    ack(id, 'failed', '"error" => ["message" => "العميل غير موجود في سند", "code" => "unknown_customer"]');
    await page.reload();
    await expect(page.getByTestId('sync-status')).toContainText('فشل التطبيق');
    await expect(page.getByTestId('sync-failed')).toContainText('unknown_customer');
    await expect(page.getByTestId('sync-failed')).toContainText('العميل غير موجود في سند');

    const retry = lastOutbox();
    expect(retry.id).not.toBe(id);
    ack(retry.id, 'applied', '"result" => ["uuid" => "x", "created" => true, "items_upserted" => 2, "items_removed" => 0, "customers_assigned" => 2]');
    await page.reload();
    await expect(page.getByTestId('sync-status')).toContainText('طُبّقت في سند');
    await expect(page.getByTestId('sync-failed')).toHaveCount(0);

    // A new publish after a change, then Sanad answers conflict (a person edited the list in Sanad): not re-queued.
    await rowAction(page, 'PL-SOAP', 'edit-line');
    await dialog(page).getByLabel(/^السعر المتفق عليه/).fill('55');
    await submitLine(page);
    await page.getByTestId('publish').click();
    await expect(page.getByTestId('sync-status')).toContainText('بانتظار سند');
    const newest = lastOutbox();
    ack(newest.id, 'conflict', '"result" => ["reason" => "local_price_list_changed"]');
    await page.reload();
    await expect(page.getByTestId('sync-status')).toContainText('تعارض في سند');
    await expect(page.getByTestId('sync-conflict')).toContainText('local_price_list_changed');
  });

  test('the list can be switched off and the lists page filters by state, with the state in the URL', async ({ page }) => {
    await openList(page, NEW_LIST);
    await page.getByRole('switch', { name: 'مفعّلة' }).click();
    await expect.poll(() => artisan(`tinker --execute='echo App\\Models\\PriceList::query()->where("name", "${NEW_LIST}")->value("is_active") ? "on" : "off";'`).trim()).toBe('off');

    await page.goto('/price-lists?active=0');
    await expect(page.locator(`[data-list="${NEW_LIST}"]`)).toBeVisible();
    await page.goto('/price-lists?active=1');
    await expect(page.locator(`[data-list="${NEW_LIST}"]`)).toHaveCount(0);
    await expect(page.getByText('لا توجد نتائج')).toBeVisible();
    await page.goto('/price-lists?sync_status=conflict');
    await expect(page.locator(`[data-list="${NEW_LIST}"]`)).toBeVisible();
  });
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`evidence ${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string, full = false): Promise<void> => {
          await settle(page);
          await expectRtlAndFits(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: full });
        };

        cleanTestLists();
        seedEvidenceList();
        // The theme menu is found by its name ("العرض"): switch it on a page where no row carries that word in its own button.
        await page.goto('/');
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await page.goto('/price-lists');
        await expect(page.locator(`[data-list="${LIST}"]`)).toBeVisible();
        await shot('lists');

        await openList(page, LIST);
        await expect(page.locator('[data-status="PL-SOAP"]')).toHaveAttribute('data-state', 'below_cost');
        await shot('products');

        await rowAction(page, 'PL-SOAP', 'edit-line');
        await expect(page.getByTestId('below-cost')).toBeVisible();
        await shot('line-below-cost');
        await page.keyboard.press('Escape');

        await page.getByTestId('add-line').click();
        await pickProduct(page, 'PL-FLOOR');
        await pickOption(page, dialog(page).getByRole('combobox', { name: /^قاعدة الهامش/ }), 'تجار الجملة');
        await expect(page.getByTestId('line-preview')).toHaveAttribute('data-state', 'missing');
        await shot('line-missing');
        await page.keyboard.press('Escape');

        await page.locator('[data-price="PL-SHAMPOO"]').getByTestId('explain-trigger').click();
        await expect(page.getByTestId('explain-panel')).toContainText('Demo cost sheet');
        await shot('explain');
        await page.keyboard.press('Escape');

        await page.getByRole('link', { name: /التجار/ }).click();
        await expect(page.locator('[data-customer="تاجر الجملة - القاهرة"]')).toBeVisible();
        await shot('merchants');

        const states: [string, string][] = [
          ['pending', '"sync_status" => "pending"'],
          ['applied', '"sync_status" => "applied", "published_at" => now(), "sync_detail" => ["items_upserted" => 3, "customers_assigned" => 2]'],
          ['failed', '"sync_status" => "failed", "published_at" => now(), "sync_detail" => ["error" => ["message" => "العميل غير موجود في سند", "code" => "unknown_customer"], "failures" => 1, "requeued" => true]'],
          ['conflict', '"sync_status" => "conflict", "published_at" => now(), "sync_detail" => ["reason" => "local_price_list_changed"]'],
        ];
        for (const [state, attributes] of states) {
          artisan(`tinker --execute='App\\Models\\PriceList::query()->where("name", "${LIST}")->first()->forceFill([${attributes}])->save();'`);
          await page.reload();
          await expect(page.getByTestId('sync-status')).toBeVisible();
          await shot(`status-${state}`);
        }

        if (theme === 'dark') {
          await page.goto('/');
          await setTheme(page, 'light');
        }
      });
    });
  }
}

test.describe('english', () => {
  test.use({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });

  test('the screens in English are left to right and worded from the same lang files', async ({ page }) => {
    cleanTestLists();
    seedEvidenceList();
    await page.goto('/price-lists');
    const saved = page.waitForResponse((r) => r.url().endsWith('/settings/locale') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: /العربية|AR|ع/ }).first().click();
    await page.getByRole('menuitemradio', { name: 'English' }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.getByRole('heading', { level: 1, name: 'Price lists' })).toBeVisible();
    await page.getByText(LIST, { exact: true }).first().click();
    await expect(page.getByRole('button', { name: 'Publish to Sanad' })).toBeVisible();
    await expect(page.locator('[data-status="PL-SOAP"]')).toHaveAttribute('data-state', 'below_cost');
    await expect(page.getByTestId('below-cost-summary')).toContainText('priced below cost');
    await expectNoHorizontalScroll(page);
    await settle(page);
    await page.screenshot({ path: `${OUT}/products-english-desktop.png` });

    const back = page.waitForResponse((r) => r.url().endsWith('/settings/locale') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: /English|EN/ }).first().click();
    await page.getByRole('menuitemradio', { name: 'العربية' }).click();
    expect((await back).status()).toBeLessThan(400);
  });
});
