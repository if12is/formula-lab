import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, setTheme, settle, stamp } from './support/kit';
import { signInAs } from './support/sign-in';

/**
 * U-07: the production role is never sent a price, a cost or a percentage, on the main screens, in Arabic RTL, light and dark, on a desktop and a 390px phone.
 * Read only, on the seeded DEMO-PROD data (ProductionDemoSeeder: unit cost 18.4573, surfactant 12 %): the spec signs in users of its own and changes no shared row.
 * Two kinds of proof: what the browser SHOWS (no cost card, no percentage column, no price entries in the menu), and what the server SENT
 * (the raw first-load HTML and the Inertia JSON of each screen, fetched with the signed-in cookie, hold no restricted key or value).
 * Screenshots go to orchestration/evidence/U-07/.
 */
const OUT = 'orchestration/evidence/U-07';
const ORDER = 'PO-2026-0002';

const RESTRICTED_KEYS = ['unit_cost', 'actual_conversion_egp', 'sell_price', 'percent_ww', 'percentWW', 'cost'];
const RESTRICTED_VALUES = ['18.4573', '51.911', '25.9555'];

async function orderUrl(page: Page): Promise<string> {
  await page.goto(`/production/orders?search=${encodeURIComponent(ORDER)}`);
  await expect(page.getByText(ORDER).first()).toBeVisible();
  await page.getByText(ORDER).first().click();
  await page.waitForURL(/\/production\/orders\/[0-9a-f-]{36}$/);
  await expect(page.getByTestId('sheet-card')).toBeVisible();

  return new URL(page.url()).pathname;
}

/** The raw bodies the server sends for a path: the first-load HTML and the Inertia JSON. */
/** The asset version of the page the browser holds (what its own Inertia requests send). */
const versionOf = (page: Page): Promise<string> => page.evaluate(() => (window as unknown as { history: { state?: { version?: string } } }).history.state?.version ?? (JSON.parse(document.querySelector('script[data-page]')?.textContent ?? '{}') as { version?: string }).version ?? '');

async function serverBodies(context: BrowserContext, page: Page, path: string): Promise<string[]> {
  const html = await context.request.get(path);
  const version = await versionOf(page);
  const json = await context.request.get(path, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version } });
  expect(html.status()).toBe(200);
  expect(json.status()).toBe(200);

  return [await html.text(), await json.text()];
}

/** The props of a body: the Inertia JSON itself, or the JSON the first-load HTML embeds. The translation dictionary is left out (it names `sell_price` as a word, not as data). */
function propsOf(body: string): string {
  const embedded = body.match(/<script data-page="[^"]*" type="application\/json">([\s\S]*?)<\/script>/)?.[1];
  const page = JSON.parse(embedded ?? body) as { props?: Record<string, unknown> } & Record<string, unknown>;
  const props = { ...(page.props ?? page) };
  delete props.translations;

  return JSON.stringify(props);
}

function expectNothingRestricted(bodies: string[], where: string): void {
  for (const body of bodies) {
    const props = propsOf(body);
    for (const key of RESTRICTED_KEYS) {
      expect(props.includes(`"${key.replaceAll('"', '')}":`), `${where} carries the key ${key}`).toBe(false);
    }
    for (const value of RESTRICTED_VALUES) {
      expect(body.includes(value), `${where} carries ${value}`).toBe(false);
    }
  }
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2, actionTimeout: 30_000 });

      test('the production role gets no price, cost or percentage; the owner still does', async ({ page, context }) => {
        test.setTimeout(240_000);
        const run = `${stamp()}-${theme}-${device}`;
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        // ---- the owner, for contrast: the same screens carry the figures -------------------------------------------------
        await signInAs(context, `${run}-owner`, 'owner', 'Owner');
        const path = await orderUrl(page);
        await expect(page.getByText('تكلفة الوحدة المعتمدة').first()).toBeVisible();
        await expect(page.getByText('النسبة %').first()).toBeVisible();
        expect((await serverBodies(context, page, path)).join('')).toContain('18.4573');
        await shot('order-owner');
        await page.goto('/');
        await expect(page.getByTestId('kpis')).toBeVisible();
        await expect(page.locator('[data-kpi="stale"]')).toHaveCount(1);
        await expect(page.locator('[data-kpi="impact"]')).toHaveCount(1);
        await shot('dashboard-owner');

        // ---- the operator: weights only -------------------------------------------------------------------------------------
        await context.clearCookies();
        await signInAs(context, `${run}-production`, 'production', 'Operator');

        await page.goto('/');
        if (theme === 'dark') {
          await setTheme(page, 'dark');
          await page.waitForLoadState('networkidle'); // saving the theme reloads the page: wait for the figures to come back
        }
        await expect(page.getByTestId('kpis-loading')).toHaveCount(0);
        await expect(page.getByTestId('kpis')).toBeVisible();
        await expect(page.locator('[data-kpi="quality"]')).toHaveCount(1);
        await expect(page.getByText('dashboard.kpi')).toHaveCount(0);
        await expect(page.locator('[data-kpi="stale"]')).toHaveCount(0);
        await expect(page.locator('[data-kpi="impact"]')).toHaveCount(0);
        await expect(page.getByTestId('card-stale')).toHaveCount(0);
        await expect(page.getByTestId('card-impact')).toHaveCount(0);
        await shot('dashboard-production');

        // The menu does not offer the screens that exist only for prices and costs.
        for (const name of ['قوائم الأسعار', 'سلّم السعر', 'الفئات والمُحسِّن']) {
          await expect(page.getByRole('link', { name })).toHaveCount(0);
        }

        const operatorOrder = await orderUrl(page);
        expect(operatorOrder).toBe(path);
        await expect(page.getByTestId('cost-card')).toHaveCount(0);
        await expect(page.getByTestId('sanad-card')).toHaveCount(0);
        await expect(page.getByText('تكلفة الوحدة المعتمدة')).toHaveCount(0);
        await expect(page.getByText('النسبة %')).toHaveCount(0);
        await expect(page.getByTestId('formula-link')).toHaveCount(0);
        await expect(page.getByTestId('sheet-total')).toBeVisible();
        await shot('order-production');

        await page.getByTestId('open-weighing').click();
        await expect(page.getByTestId('weighing-sheet')).toBeVisible();
        await shot('weighing-production');
        const weighing = new URL(page.url()).pathname;

        await page.goto(path);
        await page.getByTestId('open-batch').click();
        await expect(page.getByTestId('lines-card')).toBeVisible();
        await expect(page.getByTestId('cost-card')).toHaveCount(0);
        await shot('batch-production');
        const batch = new URL(page.url()).pathname;

        // What the server SENT: nothing restricted in the first load or in the Inertia JSON of any of these screens.
        for (const screen of ['/', path, weighing, batch, '/production/orders', '/formulas', '/materials']) {
          expectNothingRestricted(await serverBodies(context, page, screen), screen);
        }
        // The deferred figures of the home page.
        const kpis = await context.request.get('/', { headers: { 'X-Inertia': 'true', 'X-Inertia-Partial-Component': 'Dashboard', 'X-Inertia-Partial-Data': 'kpis', 'X-Inertia-Version': await versionOf(page) } });
        expect(kpis.status()).toBe(200);
        const figures = (await kpis.json()).props.kpis;
        expect(figures.stale).toBeNull();
        expect(figures.impact).toBeNull();
        expectNothingRestricted([JSON.stringify(figures)], 'dashboard figures');

        // The screens that exist only for costs, prices and the recipe answer 403, for a page visit and for a data request alike.
        for (const forbidden of ['/price-lists', '/price-ladder', '/tiers']) {
          expect((await context.request.get(forbidden)).status(), forbidden).toBe(403);
          expect((await context.request.get(forbidden, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': await versionOf(page), Accept: 'application/json' } })).status(), forbidden).toBe(403);
        }
        await page.goto('/price-lists');
        await expect(page.getByTestId('forbidden')).toBeVisible();
        await shot('price-lists-refused-production');
      });
    });
  }
}

test.describe('the accountant sees cost only', () => {
  test.use({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, actionTimeout: 30_000 });

  test('cost figures yes, percentages and price screens no', async ({ page, context }) => {
    await signInAs(context, `${stamp()}-accountant`, 'accountant', 'Accountant');
    const path = await orderUrl(page);

    await expect(page.getByText('النسبة %')).toHaveCount(0);
    expect((await serverBodies(context, page, path)).join('')).toContain('18.4573');
    expect((await context.request.get('/price-lists')).status()).toBe(403);
    await page.screenshot({ path: `${OUT}/order-accountant-light-desktop.png`, fullPage: true });
  });
});
