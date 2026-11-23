import { execSync } from 'node:child_process';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { E2E_PROVIDER_PORT } from './support/e2e-env';
import { expectNoHorizontalScroll, setTheme, settle } from './support/kit';

/**
 * P-08 and P-10 against a scripted stand-in for the model provider (e2e/support/anthropic-stub.mjs; no key exists): the consent prompt in the
 * assistant panel, a formula tool refused until the session consents, what the app really sent (temperature 0, cache breakpoints, strict
 * tools), the consent ending, the COA upload asking for the certificate consent, and the review screenshots (Arabic RTL, light and dark,
 * desktop and 390px phone) saved to orchestration/evidence/P-08-P-10/. Only the assistant's own consent and disclosure rows are written.
 */
const OUT = 'orchestration/evidence/P-08-P-10';
const PROVIDER = `http://127.0.0.1:${E2E_PROVIDER_PORT}`;

test.describe.configure({ mode: 'serial' });

type ProviderBody = {
  temperature?: number;
  system: { type: string; text: string; cache_control?: { type: string } }[];
  tools: { name: string; strict?: boolean; cache_control?: { type: string } }[];
  messages: { role: string; content: unknown }[];
};

const provider = async (request: APIRequestContext): Promise<ProviderBody[]> => (await request.get(`${PROVIDER}/__requests`)).json();

/** The disclosure log rows of this run as "outcome:surface:categories", oldest first (a read of the assistant's own table). */
const disclosures = (): string[] => {
  const out = execSync(
    `php artisan tinker --execute='echo App\\Models\\AssistantDisclosure::query()->orderBy("occurred_at")->orderBy("id")->get()->map(fn ($row) => $row->outcome->value.":".$row->surface.":".implode("+", $row->categories))->implode("|");'`,
    { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' },
  ).trim();

  return out === '' ? [] : out.split('|');
};

async function openPanel(page: Page): Promise<void> {
  await page.goto('/');
  await page.getByTestId('assistant-open').click();
  await expect(page.getByTestId('assistant-panel')).toBeVisible();
  await expect(page.getByTestId('consent-prompt')).toBeVisible();
}

async function ask(page: Page, question: string, expectedReply: string): Promise<void> {
  const before = await page.getByTestId('assistant-message-assistant').count();
  await page.getByTestId('assistant-input').fill(question);
  await page.getByTestId('assistant-send').click();
  await expect(page.getByTestId('assistant-message-assistant')).toHaveCount(before + 1);
  await expect(page.getByTestId('assistant-message-assistant').last()).toContainText(expectedReply);
}

async function allow(page: Page, labels: RegExp[]): Promise<void> {
  for (const label of labels) {
    await page.getByRole('checkbox', { name: label }).check();
  }
  await page.getByTestId('consent-allow').click();
  await expect(page.getByTestId('consent-granted')).toBeVisible();
}

const COMPOSITION = /مكونات التركيبة ونسبها/;
const COST = /تكلفة التركيبة وأسعار خاماتها/;

test('the panel asks for consent, refuses a formula tool until the session allows it, and says what it sent', async ({ page, request }) => {
  await request.delete(`${PROVIDER}/__requests`);
  await openPanel(page);
  await expect(page.getByTestId('consent-prompt')).toHaveAttribute('data-granted', 'no');
  // Nothing is ticked beforehand, and at least one category must be chosen.
  await expect(page.getByRole('checkbox', { name: COMPOSITION })).not.toBeChecked();
  await page.getByTestId('consent-allow').click();
  await expect(page.getByText('اختار فئة واحدة على الأقل.')).toBeVisible();

  // No consent: the model asks for the formula's cost, the tool is refused and the model is told so.
  await ask(page, 'كام تكلفة التركيبة دي؟', 'محتاج موافقتك');
  const refused = await provider(request);
  const toolResult = JSON.stringify(refused.at(-1)?.messages.at(-1)?.content);
  expect(toolResult).toContain('is_error');
  expect(toolResult).toContain('مكونات التركيبة');
  expect(disclosures()).toEqual(['refused:calculate_formula:formula_composition+formula_cost']);

  // What the app sent: temperature 0, the system prompt and the last tool cached, every tool strict, nothing cached in the conversation.
  const sent = refused[0];
  expect(sent.temperature).toBe(0);
  expect(sent.system).toHaveLength(1);
  expect(sent.system[0].cache_control).toEqual({ type: 'ephemeral' });
  expect(sent.tools.at(-1)?.cache_control).toEqual({ type: 'ephemeral' });
  expect(sent.tools.slice(0, -1).every((tool) => tool.cache_control === undefined)).toBe(true);
  expect(sent.tools.every((tool) => tool.strict === true)).toBe(true);
  expect(JSON.stringify(sent.messages)).not.toContain('cache_control');

  // Allow composition and cost for this session: the same tool now runs, and the log says what category was sent.
  await allow(page, [COMPOSITION, COST]);
  await ask(page, 'كام تكلفة التركيبة دي؟', 'الأداة اتنفذت');
  expect(disclosures()).toEqual(['refused:calculate_formula:formula_composition+formula_cost', 'sent:calculate_formula:formula_composition+formula_cost']);

  // The consent belongs to the session, not the page: a reload still shows it, and withdrawing it brings the prompt back.
  await page.reload();
  await page.getByTestId('assistant-open').click();
  await expect(page.getByTestId('consent-granted')).toBeVisible();
  await page.getByTestId('consent-revoke').click();
  await expect(page.getByTestId('consent-prompt')).toHaveAttribute('data-granted', 'no');
  await ask(page, 'كام تكلفة التركيبة دي؟', 'محتاج موافقتك');
  expect(disclosures().at(-1)).toBe('refused:calculate_formula:formula_composition+formula_cost');
});

test('the certificate upload asks for the certificate consent and cannot read before it', async ({ page }) => {
  await page.goto('/coa');
  await page.getByTestId('coa-upload-open').click();
  const prompt = page.getByTestId('consent-prompt');
  await expect(prompt).toBeVisible();
  await expect(prompt).toContainText('قراءة شهادة التحليل بتبعت الملف');
  await expect(page.getByRole('checkbox')).toHaveCount(1);
  await expect(page.getByTestId('coa-upload-submit')).toBeDisabled();

  await page.getByRole('checkbox', { name: /شهادات تحليل الموردين/ }).check();
  await prompt.getByTestId('consent-allow').click();
  await expect(page.getByTestId('consent-granted')).toBeVisible();
  // Still disabled: the material and the file are missing, but the consent no longer blocks.
  await expect(page.getByTestId('coa-upload-submit')).toBeDisabled();
  await page.getByTestId('consent-revoke').click();
});

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`screens ${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2 });

      test('screens', async ({ page }) => {
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        await page.goto('/');
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await page.getByTestId('assistant-open').click();
        await expect(page.getByTestId('consent-prompt')).toHaveAttribute('data-granted', 'no');
        await shot('panel-consent');

        await ask(page, 'كام تكلفة التركيبة دي؟', 'محتاج موافقتك');
        await shot('panel-refused');

        await allow(page, [COMPOSITION, COST]);
        await ask(page, 'كام تكلفة التركيبة دي؟', 'الأداة اتنفذت');
        await shot('panel-allowed');
        await page.getByTestId('consent-revoke').click();
        await page.keyboard.press('Escape');

        await page.goto('/coa');
        await page.getByTestId('coa-upload-open').click();
        await expect(page.getByTestId('consent-prompt')).toBeVisible();
        await shot('coa-upload-consent');
      });
    });
  }
}
