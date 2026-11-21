import { execSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';
import { pickOption, pickRecord, settle } from './kit';

/** Seeds the demo formula, the three protocol templates and two demo trials of the formula (idempotent). */
export function seedLabDemo(): void {
  execSync('php artisan db:seed --class=LabDemoSeeder --force', { cwd: 'apps/api', stdio: 'pipe' });
}

/**
 * Creates a one-line formula (100 % of the demo water) through the editor, so a spec has a draft version of its own to move
 * through the workflow (an approved version can never be reset). Lands on version 1 of the new formula.
 */
export async function createDraftFormula(page: Page, code: string, name: string): Promise<void> {
  await page.goto('/formulas/create');
  await expect(page.getByRole('heading', { level: 1, name: 'تركيبة جديدة' })).toBeVisible();
  await settle(page);
  await page.getByLabel(/^الكود/).fill(code);
  await page.getByLabel(/^الاسم\s*\*?$/).fill(name);
  await page.getByLabel(/^نوع المنتج/).fill('shampoo');
  await pickRecord(page, page.getByRole('combobox', { name: 'خامة السطر 1' }), 'Demo Water');
  await page.getByRole('textbox', { name: 'نسبة السطر 1' }).fill('100');
  await page.getByRole('button', { name: 'إنشاء التركيبة' }).click();
  await expect(page).toHaveURL(/\/formulas\/[0-9a-f-]{36}\/versions\/1\/edit/);
  await expect(page.getByTestId('workflow-panel')).toHaveAttribute('data-status', 'draft');
}

/** Signs a workflow move through the panel's modal: opens it from `button`, types the text if any and confirms. */
export async function signMove(page: Page, button: string, text?: string): Promise<void> {
  await page.getByRole('button', { name: button, exact: true }).click();
  const modal = page.getByRole('dialog');
  if (text !== undefined) {
    await modal.getByTestId('workflow-text').fill(text);
  }
  await modal.getByTestId('workflow-confirm').click();
}

/** Records one measurement on an open trial's page through the form. */
export async function recordMeasurement(page: Page, m: { property: string; value: string; unit?: string; method?: string; instrument: string }): Promise<void> {
  const form = page.getByTestId('measurement-form');
  await pickOption(page, form.getByTestId('measurement-property'), m.property);
  if (m.unit) {
    await pickOption(page, form.getByTestId('measurement-unit'), m.unit);
  }
  await form.getByLabel(/^القيمة/).fill(m.value);
  if (m.method) {
    await form.getByLabel(/^الطريقة/).fill(m.method);
  }
  await form.getByLabel(/^الجهاز المستخدم/).fill(m.instrument);
  await form.getByTestId('measurement-submit').click();
}
