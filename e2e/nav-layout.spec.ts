import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalScroll, setNavLayout, setTheme, settle } from './support/kit';

/**
 * Top navigation (kit 1.9.0): the person switches from the Display menu, the server keeps it in the
 * fl_nav_layout cookie, and the one navigation definition draws as a bar of dropdown groups.
 */
const bar = (page: Page) => page.locator('nav.kit-topnav');

// What config/navigation.js promises: [group label, its items], in Arabic.
const GROUPS: Array<[string, string[]]> = [
  ['التركيبات', ['التركيبات', 'الخامات', 'عائلات المنتجات', 'قوالب التركيبات', 'التغليف', 'الحاسبات']],
  ['الجودة', ['المطابقة', 'المعمل', 'بروتوكولات الاختبار']],
  ['التشغيل', ['قوائم الأسعار', 'الإنتاج', 'تتبع اللوط']],
  ['النظام', ['الإعدادات', 'أسعار الصرف']],
];

test.describe('top navigation', () => {
  test('starts with the sidebar, switches from the Display menu and survives a reload (server cookie)', async ({ page, context }) => {
    await page.goto('/formulas');
    await expect(page.locator('html')).toHaveAttribute('data-nav-layout', 'sidebar');
    await expect(page.locator('#kit-sidebar')).toBeVisible();
    await expect(bar(page)).toHaveCount(0);

    await setNavLayout(page, 'topbar');
    await expect(bar(page)).toBeVisible();
    await expect(page.locator('#kit-sidebar')).toBeHidden();
    await expect(page.locator('[data-sonner-toast]').getByText('تم حفظ شكل القائمة.')).toBeVisible();
    expect((await context.cookies()).some((cookie) => cookie.name === 'fl_nav_layout')).toBe(true);

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-nav-layout', 'topbar');
    await expect(bar(page)).toBeVisible();

    // A fresh page in the same context is server-rendered with it too (no localStorage involved).
    await page.evaluate(() => localStorage.clear());
    await page.goto('/materials');
    await expect(page.locator('html')).toHaveAttribute('data-nav-layout', 'topbar');

    await setNavLayout(page, 'sidebar');
    await page.reload();
    await expect(page.locator('#kit-sidebar')).toBeVisible();
  });

  test('the groups open as dropdowns and the current group is lit', async ({ page }) => {
    await page.goto('/formulas');
    await setNavLayout(page, 'topbar');
    await page.reload();

    // The dashboard is a plain pill; the four groups are dropdowns.
    await expect(bar(page).getByRole('link', { name: 'لوحة التحكم' })).toBeVisible();
    await expect(bar(page).getByRole('button')).toHaveCount(GROUPS.length);

    // On /formulas the Formulation group is the current one, the others are not.
    await expect(bar(page).getByRole('button', { name: 'التركيبات' })).toHaveAttribute('aria-current', 'true');
    for (const [label] of GROUPS.slice(1)) {
      await expect(bar(page).getByRole('button', { name: label })).not.toHaveAttribute('aria-current', 'true');
    }

    for (const [label, items] of GROUPS) {
      const trigger = bar(page).getByRole('button', { name: label });
      await expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
      await trigger.click();
      await expect(trigger).toHaveAttribute('aria-expanded', 'true');
      const menu = page.getByRole('menu');
      await expect(menu.getByRole('menuitem')).toHaveCount(items.length);
      for (const item of items) {
        await expect(menu.getByRole('menuitem', { name: item, exact: true })).toBeVisible();
      }
      if (label === 'التركيبات') {
        await expect(menu.getByRole('menuitem', { name: 'التركيبات', exact: true })).toHaveAttribute('aria-current', 'page');
      }
      await page.keyboard.press('Escape');
      await expect(trigger).toBeFocused();
    }

    await bar(page).getByRole('button', { name: 'التركيبات' }).click();
    await page.getByRole('menuitem', { name: 'الخامات' }).click();
    await expect(page).toHaveURL(/\/materials$/);
    await expect(bar(page).getByRole('button', { name: 'التركيبات' })).toHaveAttribute('aria-current', 'true');
  });

  test('keeps the language and display controls (the display currency is inside the Display menu) at the end of the bar, in both directions', async ({ page }) => {
    await page.goto('/');
    await setNavLayout(page, 'topbar');

    const inBar = page.locator('header').first();
    for (const direction of ['rtl', 'ltr'] as const) {
      if (direction === 'ltr') {
        await page.getByRole('button', { name: /العربية|AR|ع/ }).first().click();
        await page.getByRole('menuitemradio', { name: 'English' }).click();
        await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
      }
      await expect(page.locator('html')).toHaveAttribute('data-nav-layout', 'topbar');
      await expectNoHorizontalScroll(page);

      const navBox = await bar(page).boundingBox();
      const display = await inBar.getByRole('button', { name: /^(العرض|Display)$/ }).boundingBox();
      const locale = await inBar.getByRole('button', { name: /العربية|English|AR|EN|ع/ }).first().boundingBox();
      expect(navBox && display && locale).toBeTruthy();
      // The controls sit on the far side of the items: right of them in LTR, left of them in RTL.
      for (const box of [display!, locale!]) {
        if (direction === 'ltr') {
          expect(box.x).toBeGreaterThan(navBox!.x + navBox!.width - 1);
        } else {
          expect(box.x + box.width).toBeLessThan(navBox!.x + 1);
        }
      }
    }
  });

  for (const theme of ['light', 'dark'] as const) {
    test(`renders in ${theme} mode without sideways scroll`, async ({ page }) => {
      await page.goto('/formulas');
      await setNavLayout(page, 'topbar');
      if (theme === 'dark') {
        await setTheme(page, 'dark');
      }
      await settle(page);
      await expect(page.locator('html')).toHaveAttribute('data-mode', theme);
      await expect(bar(page).getByRole('button', { name: 'التركيبات' })).toBeVisible();
      await expectNoHorizontalScroll(page);
    });
  }
});

test.describe('top navigation on a phone', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('uses the drawer with the same navigation and no horizontal scroll', async ({ page }) => {
    await page.goto('/formulas');
    await setNavLayout(page, 'topbar');
    await page.reload();

    await expect(bar(page)).toBeHidden();
    await expectNoHorizontalScroll(page);

    const drawer = page.locator('#kit-sidebar');
    await expect(drawer).toHaveAttribute('inert', '');
    await page.getByRole('button', { name: 'إظهار القائمة' }).click();
    await expect(drawer).toHaveAttribute('role', 'dialog');
    for (const [, items] of GROUPS) {
      for (const item of items) {
        await expect(drawer.getByRole('link', { name: item }).first()).toBeVisible();
      }
    }
    await expectNoHorizontalScroll(page);

    await drawer.getByRole('link', { name: 'الخامات' }).click();
    await expect(page).toHaveURL(/\/materials$/);
    await expect(drawer).toHaveAttribute('inert', '');
  });
});
