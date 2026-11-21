import { expect, test } from '@playwright/test';

test.describe('app shell', () => {
  test('dashboard renders in Arabic RTL with brand and sidebar navigation', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html[dir=rtl]')).toHaveCount(1);
    await expect(page.locator('html[lang=ar]')).toHaveCount(1);
    await expect(page.locator('html[data-theme-source=server]')).toHaveCount(1);

    const sidebar = page.locator('#kit-sidebar');
    await expect(sidebar.getByText('Formula Lab').first()).toBeVisible();
    const nav = sidebar.getByRole('navigation');
    await expect(nav).toBeVisible();
    // dashboard, formulas, materials, calculators, compliance, lab, production, settings, exchange rates
    await expect(nav.getByRole('link')).toHaveCount(9);
    await expect(nav.getByRole('link', { name: 'لوحة التحكم' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { level: 1, name: 'لوحة التحكم' })).toBeVisible();
    await expect(page).toHaveTitle('لوحة التحكم - Formula Lab');
  });

  test('missing KPI figures show a dash with the reason, never zero', async ({ page }) => {
    await page.goto('/');
    const cards = page.locator('main section').first().locator('section');
    await expect(cards).toHaveCount(4);
    for (const card of await cards.all()) {
      await expect(card).toContainText('—');
      await expect(card).toContainText('لا توجد بيانات بعد');
      await expect(card).not.toHaveText(/(^|\s)0(\s|$)/);
    }
  });

  test('a section that is not built yet shows the coming-soon page', async ({ page }) => {
    await page.goto('/lab');
    await expect(page.getByRole('heading', { level: 1, name: 'هذه الشاشة قيد التنفيذ' })).toBeVisible();
    await expect(page.locator('#kit-sidebar').getByRole('link', { name: 'المعمل' }).first()).toHaveAttribute('aria-current', 'page');
  });

  test('switching the theme to dark sets data-mode=dark and survives a reload', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'light');

    const saved = page.waitForResponse((response) => response.url().endsWith('/settings/theme') && response.request().method() === 'PUT');
    await page.getByRole('button', { name: 'العرض' }).click();
    await page.getByRole('menuitemradio', { name: 'داكن' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'dark');
    expect((await saved).status()).toBeLessThan(400);

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'dark');
    await expect(page.locator('html')).toHaveAttribute('data-mode', 'dark');
  });

  test('switching the language to English flips to LTR and English text', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /العربية|AR|ع/ }).first().click();
    await page.getByRole('menuitemradio', { name: 'English' }).click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
    // The server flashes the confirmation in the newly chosen language; the layout shows it as a kit toast.
    await expect(page.locator('[data-sonner-toast]').getByText('Language saved.')).toBeVisible();
  });

  test('saving the theme shows the server confirmation as a toast', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'العرض' }).click();
    await page.getByRole('menuitemradio', { name: 'داكن' }).click();
    await expect(page.locator('[data-sonner-toast]').getByText('تم حفظ المظهر.')).toBeVisible();
  });
});

test.describe('phone width', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('no horizontal scroll and the sidebar opens as a drawer', async ({ page }) => {
    await page.goto('/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const sidebar = page.locator('#kit-sidebar');
    await expect(sidebar).toHaveAttribute('inert', '');
    await page.getByRole('button', { name: 'إظهار القائمة' }).click();
    await expect(sidebar).toHaveAttribute('role', 'dialog');
    await expect(sidebar.getByRole('link', { name: 'الخامات' })).toBeVisible();

    await sidebar.getByRole('link', { name: 'الخامات' }).click();
    await expect(page).toHaveURL(/\/materials$/);
    await expect(sidebar).toHaveAttribute('inert', '');
  });
});
