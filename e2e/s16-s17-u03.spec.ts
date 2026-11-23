import { execSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import { E2E_GATE_PORT } from './support/e2e-env';
import { expectNoHorizontalScroll, setTheme, settle } from './support/kit';
import { signInAsQualityManager } from './support/sign-in';

/**
 * S-16 the terms-of-use gate, S-17 the print preview with watermark and the version hash, U-03 the export log, in Arabic RTL, light and dark,
 * on a desktop and a 390px phone.
 *   S-16 runs against the second server (E2E_GATE_PORT) where the gate is ON: an unaccepted visitor of any page lands on the terms (marked as a
 *        draft), cannot go on without ticking, and after accepting is sent back to the page they asked for. The acceptance row carries the version and
 *        the wording hash; the re-ask on a version or wording change is a PHPUnit test (the server's config cannot change mid-run).
 *   S-17 opens the preview of the label, the cost report, the weighing sheet and the lot trace (all four share one PreviewShell): the paper shows the
 *        watermark, the hash of the version (read from the database here, so the page cannot be marking its own homework) and, after "Print", the
 *        export reference and time. window.print is replaced by a counter: a print dialog cannot be driven in headless mode.
 *   U-03 reads the export log list those prints made: who (the signed-in quality manager), what, when, hash; filters and search kept in the URL.
 * Screenshots go to orchestration/evidence/S-16-S-17-U-03/.
 */
const OUT = 'orchestration/evidence/S-16-S-17-U-03';
const GATE = `http://127.0.0.1:${E2E_GATE_PORT}`;
const HASH = /^[0-9a-f]{64}$/;

const tinker = (php: string): string => execSync(`php artisan tinker --execute='${php}'`, { cwd: 'apps/api', stdio: 'pipe', encoding: 'utf8' }).trim();

/** What the database holds for the seeded demo documents (DEMO-PROD, its order PO-2026-0002 and one weighed lot). */
function demo(): { formulaId: string; versionHash: string; orderId: string; orderCode: string; lot: string } {
  const out = tinker(
    'echo json_encode(["formulaId" => App\\Models\\Formula::query()->where("code", "DEMO-PROD")->value("id"), '
      + '"versionHash" => App\\Models\\FormulaVersion::query()->whereHas("formula", fn ($q) => $q->where("code", "DEMO-PROD"))->where("version", 1)->value("hash"), '
      + '"orderId" => App\\Models\\ProductionOrder::query()->where("code", "PO-2026-0002")->value("id"), "orderCode" => "PO-2026-0002", '
      + '"lot" => App\\Models\\ProductionBatchLine::query()->whereNotNull("lot_number")->value("lot_number")]);',
  );

  return JSON.parse(out);
}

async function printCalls(page: Page): Promise<number> {
  return page.evaluate(() => (window as unknown as { __printCalls?: number }).__printCalls ?? 0);
}

for (const theme of ['light', 'dark'] as const) {
  for (const [device, viewport] of [['desktop', { width: 1280, height: 900 }], ['mobile', { width: 390, height: 844 }]] as const) {
    test.describe(`${theme} ${device}`, () => {
      test.use({ viewport, deviceScaleFactor: 2, actionTimeout: 30_000 });

      test('terms gate: ask, refuse without the tick, accept, return to the page asked for', async ({ browser }) => {
        test.setTimeout(180_000);
        const context = await browser.newContext({ viewport, deviceScaleFactor: 2 });
        const page = await context.newPage();
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };
        const before = Number(tinker('echo App\\Models\\TermsAcceptance::query()->count();'));

        // Any page sends a visitor who has not accepted to the terms.
        await page.goto(`${GATE}/formulas`);
        await expect(page).toHaveURL(`${GATE}/terms`);
        await expect(page.getByRole('heading', { level: 1, name: 'شروط الاستخدام وإخلاء المسؤولية' })).toBeVisible();
        if (theme === 'dark') {
          await setTheme(page, 'dark');
        }
        await expect(page.getByTestId('terms-draft')).toContainText('مسودة');
        await expect(page.locator('[data-section]')).toHaveCount(5);
        await expect(page.locator('[data-section="lab_trial"]')).toContainText('الثبات والسلامة بتتأكد بتجربة المعمل');
        await expect(page.locator('[data-section="assistant"]')).toContainText('عمره ما بيحسب');
        await shot('terms-gate');

        // Without the tick nothing is recorded and the person stays here.
        await page.getByTestId('terms-accept').click();
        await expect(page.getByText('علّم على المربع عشان توافق على الشروط قبل ما تكمّل.').first()).toBeVisible();
        await expect(page).toHaveURL(`${GATE}/terms`);
        expect(Number(tinker('echo App\\Models\\TermsAcceptance::query()->count();'))).toBe(before);
        await shot('terms-gate-error');

        // The tick: the acceptance is recorded and the person lands on the page they first asked for.
        await page.getByRole('checkbox').check();
        await page.getByTestId('terms-accept').click();
        await expect(page).toHaveURL(`${GATE}/formulas`, { timeout: 20_000 });
        const row = JSON.parse(tinker('$a = App\\Models\\TermsAcceptance::query()->orderByDesc("accepted_at")->first(); echo json_encode(["version" => $a->terms_version, "hash" => $a->terms_hash, "user" => $a->user_id, "key" => $a->session_key]);'));
        expect(row.version).toBe('2026-11-draft-1');
        expect(row.hash).toMatch(HASH);
        expect(row.user).toBeNull();
        expect(Number(tinker('echo App\\Models\\TermsAcceptance::query()->count();'))).toBe(before + 1);

        // Afterwards every page is open, and the terms stay readable from the user menu's address with the acceptance shown.
        await page.goto(`${GATE}/materials`);
        await expect(page).toHaveURL(`${GATE}/materials`);
        await page.goto(`${GATE}/terms`);
        await expect(page.getByTestId('terms-accepted')).toBeVisible();
        await shot('terms-accepted');
        await context.close();
      });

      test('print preview on the four documents, the export log of those prints, and the log refusing to print when it cannot write', async ({ page, context }) => {
        test.setTimeout(420_000);
        const run = `${Date.now().toString(36)}-${theme}-${device}`;
        const data = demo();
        expect(data.versionHash).toMatch(HASH);
        await signInAsQualityManager(context, run);
        await page.addInitScript(() => {
          (window as unknown as { __printCalls: number }).__printCalls = 0;
          window.print = () => { (window as unknown as { __printCalls: number }).__printCalls += 1; };
        });
        const shot = async (name: string): Promise<void> => {
          await settle(page);
          await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
          await expectNoHorizontalScroll(page);
          await page.screenshot({ path: `${OUT}/${name}-${theme}-${device}.png`, fullPage: true });
        };

        const documents = [
          { id: 'label', url: `/formulas/${data.formulaId}/versions/1/label`, kind: 'بطاقة التركيبة', hash: data.versionHash },
          { id: 'cost-report', url: `/formulas/${data.formulaId}/cost-report?version=1`, kind: 'تقرير التكلفة', hash: data.versionHash },
          { id: 'weighing-sheet', url: `/production/orders/${data.orderId}/weighing`, kind: 'ورقة الوزن', hash: data.versionHash },
          { id: 'lot-trace', url: `/production/trace?lot=${encodeURIComponent(data.lot)}`, kind: 'تتبع اللوط', hash: null },
        ];

        let themed = false;
        for (const doc of documents) {
          await page.goto(doc.url);
          await expect(page.getByTestId('open-preview')).toBeVisible();
          if (theme === 'dark' && !themed) {
            await setTheme(page, 'dark');
            themed = true;
          }

          // The page as usual: the paper furniture is not on screen.
          await expect(page.getByTestId('paper-header')).toBeHidden();
          await expect(page.getByTestId('watermark')).toBeHidden();

          await page.getByTestId('open-preview').click();
          await expect(page.getByTestId('preview-toolbar')).toBeVisible();
          await expect(page.getByTestId('print-paper')).toHaveAttribute('data-mode', 'light');
          await expect(page.getByTestId('watermark')).toBeVisible();
          await expect(page.getByTestId('paper-header')).toContainText(doc.kind);
          const shownHash = (await page.getByTestId('paper-hash').innerText()).trim();
          expect(shownHash).toMatch(HASH);
          if (doc.hash) {
            expect(shownHash, 'the hash on the paper is the stored version hash').toBe(doc.hash);
          }
          await expect(page.getByTestId('paper-footer')).toContainText(shownHash);
          await expect(page.getByTestId('paper-note')).toContainText('لسه ما اتطبعتش');
          await shot(`${doc.id}-preview`);

          // Print: the log first, then the (replaced) print window; the paper then carries the reference and the time.
          await page.getByTestId('print-document').click();
          await expect(page.getByTestId('paper-reference')).toHaveText(/^[0-9A-F]{8}$/);
          await expect(page.getByTestId('paper-printed-at')).toBeVisible();
          expect(await printCalls(page)).toBe(1);
          if (doc.id === 'label') {
            await shot('label-after-print');
            // What the printer gets: the same paper in print media, without the toolbar.
            await page.emulateMedia({ media: 'print' });
            await expect(page.getByTestId('preview-toolbar')).toBeHidden();
            await expect(page.getByTestId('watermark')).toBeVisible();
            await expect(page.getByTestId('paper-hash')).toBeVisible();
            await page.screenshot({ path: `${OUT}/label-print-media-${theme}-${device}.png`, fullPage: true });
            await page.emulateMedia({ media: 'screen' });
          }

          await page.getByTestId('close-preview').click();
          await expect(page.getByTestId('preview-toolbar')).toBeHidden();
        }

        // The browser's own print menu (no preview): the watermark and the hash are still on paper, and the page says there is no export reference.
        await page.goto(documents[0].url);
        await expect(page.getByTestId('open-preview')).toBeVisible();
        await page.emulateMedia({ media: 'print' });
        await expect(page.getByTestId('watermark')).toBeVisible();
        await expect(page.getByTestId('paper-hash')).toHaveText(data.versionHash);
        await expect(page.getByTestId('paper-note')).toContainText('مش مطبوعة من معاينة الطباعة');
        await expect(page.getByTestId('open-preview')).toBeHidden();
        await page.emulateMedia({ media: 'screen' });

        // A deep link opens the preview directly.
        await page.goto(`${documents[1].url}&preview=1`);
        await expect(page.getByTestId('preview-toolbar')).toBeVisible();

        // If the log cannot be written, nothing goes to the printer.
        await page.route('**/exports', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 500, body: '{}' }) : route.continue()));
        const before = await printCalls(page);
        await page.getByTestId('print-document').click();
        await expect(page.getByText('الطباعة ما اتسجلتش فما اتبعتتش للطابعة. جرّب تاني.').first()).toBeVisible();
        expect(await printCalls(page)).toBe(before);
        await expect(page.getByTestId('paper-reference')).toHaveCount(0);
        await page.unroute('**/exports');

        // ---- U-03 the export log ------------------------------------------------------------------------------------------------
        await page.goto('/exports');
        await expect(page.getByRole('heading', { level: 1, name: 'سجل التصدير' })).toBeVisible();
        // The hash column is a desktop column (the phone shows each print as a row with its subject); the rows are the proof there.
        const rows = device === 'mobile' ? page.locator('[data-mobile-row]') : page.getByTestId('export-hash');
        await expect(rows.first()).toBeVisible();
        await shot('export-log');

        // Who: the signed-in quality manager. Search by the full hash finds the prints of that version.
        await page.goto(`/exports?search=${data.versionHash}`);
        if (device === 'desktop') {
          await expect(page.getByText(`E2E Quality ${run}`).first()).toBeVisible();
        }
        const byHash = device === 'mobile' ? page.locator('[data-mobile-row]') : page.locator('[data-testid="export-hash"]');
        expect(await byHash.count()).toBeGreaterThanOrEqual(3);
        if (device === 'desktop') {
          for (const title of await byHash.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('title')))) {
            expect(title).toBe(data.versionHash);
          }
        }

        // Filters kept in the URL: only the cost reports, then cleared.
        await page.goto('/exports?kind=cost_report');
        await expect(page).toHaveURL(/kind=cost_report/);
        await expect(rows.first()).toBeVisible();
        const kinds = await page.locator('tbody tr, [data-mobile-row]').allInnerTexts();
        expect(kinds.length).toBeGreaterThan(0);
        for (const text of kinds) {
          expect(text).toContain('تقرير التكلفة');
        }
        await shot('export-log-filtered');

        await page.goto('/exports?search=zzz-no-such-document');
        await expect(page.getByText('لا توجد نتائج تطابق هذه الفلاتر').first()).toBeVisible();
        await shot('export-log-empty');
      });
    });
  }
}
