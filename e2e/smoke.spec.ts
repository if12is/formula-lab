import { expect, test } from '@playwright/test';

test('home page loads in RTL with app name', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('html[dir=rtl]')).toHaveCount(1);
  await expect(page.getByText('Formula Lab').first()).toBeVisible();
});
